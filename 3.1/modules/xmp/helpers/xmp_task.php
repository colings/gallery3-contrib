<?php defined("SYSPATH") or die("No direct script access.");
/**
* Gallery - a web based photo album viewer and editor
* Copyright (C) 2000-2010 Bharat Mediratta
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or (at
* your option) any later version.
*
* This program is distributed in the hope that it will be useful, but
* WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
* General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA 02110-1301, USA.
*/
class xmp_task_Core {
  static function available_tasks() {
    $lastItemScanned = module::get_var("xmp","lastItemScanned","1");
    return array(Task_Definition::factory()
                 ->callback("xmp_task::statueOfLibertyAddress")
                 ->name(t("Test extraction of XMP data"))
                 ->description(t("Scan an image of the Statue of Liberty."))
                 ->severity(log::INFO),
               Task_Definition::factory()
                 ->callback("xmp_task::extract_xmp")
                 ->name(t("Extract XMP data"))
                 ->description(t("Scan current images, starting with item {$lastItemScanned}, and attempt to extract basic XMP without duplication."))
                 ->severity(log::INFO)
        );
  }

  static function extract_xmp($task) {
    //http://github.com/gallery/gallery3/blob/master/modules/gallery/helpers/task.php
    //http://github.com/gallery/gallery3/blob/master/modules/exif/helpers/exif_task.php
    try {
      //http://github.com/gallery/gallery3/blob/master/modules/gallery/helpers/module.php#L427
      //http://github.com/gallery/gallery3/blob/master/modules/gallery/helpers/module.php#L486
      //keep a tally of all the items scanned so far. 
     
      $lastItemScanned = module::get_var("xmp","lastItemScanned","1");
      if( !is_numeric($lastItemScanned) ) {
        $lastItemScanned = 1;
        message::error("During the last XMP scan, the id of the item to start scanning was not a number, so the task reset the id to 1.");
        $task->log("During the last XMP scan, the id of the item to start scanning was not a number, so the task reset the id to 1.");
      }

      $start = microtime(true);
      $i = 0;
      foreach( ORM::factory("item")
               ->where("type", "=", "photo")
               ->and_where("id", ">=", "{$lastItemScanned}")
               ->find_all(100) as $item) {
        // The query above can take a long time, so start the timer after its done
        // to give ourselves a little time to actually process rows.
        if (!isset($start)) {
          $start = microtime(true);
        }

        $task->log("Scanning \"{$item->title}\" (id {$item->id}) from {$item->file_path()}");
        xmp::extract($item);
        $lastItemScanned = $item->id;
        module::set_var("xmp","lastItemScanned",$lastItemScanned);
        $task->percent_complete = $i++;
        if (microtime(true) - $start > .75) {
          break;
        }
      }

      $task->done = true;
      $task->state = "success";
      $task->status = "Successfully scanned {$i} photos. Check the log to see the list of items scanned. Run the task again if need be.";
      $task->percent_complete = 100;
    } catch (Exception $e) {
      $task->done = true;
      $task->state = "error";
      $task->status = $e->getMessage();
      $task->log((string)$e);
    }
  }

  static function check_children_for_faces($childNodes) {
    $bSuccess = false;
    if($childNodes->length > 0 ) {
      $bPerson = false;
      $bRectangle = false;
      for($i=0; $i < $childNodes->length; $i++) {
        if( $childNodes->item($i)->nodeName == "MPReg:PersonDisplayName" ) {
          $bPerson = true;
        } elseif ( $childNodes->item($i)->nodeName == "MPReg:Rectangle" ) {
          $bRectangle = true;
        }
      }
      if($bPerson && $bRectangle) {
        $bSuccess = true;
      }
    }
    return $bSuccess;
  }
  
  static function recursive_extract($task,$baseNode,$depth,$bFaces) {
    
    //$task->log("Entering recursive check on nodes at depth: {$depth}");
    
    
    $bSuccess = false;
    $aRectangles = array();
    $aPeople = array();

    if( $bFaces ) { //previous level told us we have children, so extract them!
      $bSuccess = true;
      //$task->log("This level has faces. Found {$baseNode->length} children to look at depth {$depth}!");
      for($i=0; $i < $baseNode->length; $i++) { //for each item in the DOMNodeList ($baseNode)
        $reg = $baseNode->item($i);
        if( $reg->nodeName == "MPReg:PersonDisplayName" ) {
          $aPeople[] = $reg->nodeValue;
          //$task->log("    Found a person: {$reg->nodeValue}");
        } elseif ( $reg->nodeName == "MPReg:Rectangle" ) {
          $aRectangles[] = $reg->nodeValue;
          //$task->log("    Found a rectangle: {$reg->nodeValue}");
        }
      }
    } else {
      //baseNode is a DOMNodeList, so whatever I do, I need to check that it has a length
      if ( $baseNode->length > 0 ) {// if there is a length, I need to go through my usual checks
        //$task->log("Found {$baseNode->length} children to look at depth {$depth}!");
        //if there are exactly two nodes, it might be the region info;
        for($i=0; $i < $baseNode->length; $i++) { //for each item in the DOMNodeList ($baseNode)
          $node = $baseNode->item($i);
          //$task->log("  {$node->nodeName}:{$node->nodeValue}");
          //check for attributes
          if( $node->hasAttributes() ){
            //$task->log("  Found attributes to look at!");
            $attributes = $node->attributes;
            if(!is_null($attributes)) {
              $bPerson = false;
              $person = "";
              $bRectangle = false;
              $rectangle = "";
              foreach ($attributes as $index=>$attr) {
                if( $attr->name == "PersonDisplayName" ) {
                  $bPerson = true;
                  $person = $attr->value;
                } elseif ($attr->name == "Rectangle" ) {
                  $bRectangle = true;
                  $rectangle = $attr->value;
                } 
              }
              if( $bPerson && $bRectangle ) {
                //$task->log("    Found a rectangle: {$attr->value}");
                //$task->log("    Found a person: {$attr->value}");
                $aRectangles[] = $rectangle;
                $aPeople[] = $person;
                $bSuccess = true;
              }
            }
          }
            
          //check the children
          if ($node->hasChildNodes() ) {
            $childNodes = $node->childNodes;
            $bFaces = xmp_task::check_children_for_faces($childNodes);
            if( $bFaces ) {
              //$task->log("  Next level down has faces!");
              list( $bTmpSuccess, $aTmpRectangles, $aTmpPeople ) = xmp_task::recursive_extract($task,$childNodes,$depth+1,true);
            } else {
              list( $bTmpSuccess, $aTmpRectangles, $aTmpPeople ) = xmp_task::recursive_extract($task,$childNodes,$depth+1,false);
            }
            $bSuccess += $bTmpSuccess;
            $aRectangles = array_merge($aRectangles, $aTmpRectangles);
            $aPeople = array_merge($aPeople, $aTmpPeople);
          }
        } //on of for loop (over each item in DOMNodeList)
      }
    }    
    //$task->log("Exiting recursive level {$depth}");
    
    $aReturn = array($bSuccess, $aRectangles, $aPeople);
    return $aReturn;
  }


  static function statueOfLibertyAddress($task) {
    $bWarning = false;
    $bError = false;
    $bNoXMP = false;
    $bSuccess = false;
    $tags = $task->get("tags");
    if (empty($tags)) {
      $task->set("tags", "No tags found.");
      $task->set("faces", 0);
    }
    $task->log( t("Trying to open file") );
    if(file_exists(MODPATH . "xmp/lib/P1020060.JPG")) {
      $task->log( t( "Image file exists!" ) );
      if(xmp::file_exists_ip(MODPATH . "xmp/lib/Image/JpegXmpReader.php") || file_exists(MODPATH . "xmp/lib/Image/JpegXmpReader.php") ){
        require_once( MODPATH . "xmp/lib/Image/JpegXmpReader.php" );
      }
      if( class_exists("Image_JpegXmpReader")) {
       
        try {
          $xmpReader = new Image_JpegXmpReader( MODPATH . "xmp/lib/P1020060.JPG");
          $xmp = $xmpReader->readXmp();
        } catch (Exception $e) {
          $bError = true;
          Kohana_Log::add("error", "[XMP Module] Error reading XML.\n" .
                          $e->getMessage() . "\n" . $e->getTraceAsString());
        }
        
        if ($xmp === false) {
          $bNoXMP = true;
          $bError = true;
          $task->log("No XMP data could be found! Make sure that the file HAS XMP data and try again. ");            
        } 

        if(!$bError && !$bNoXMP) {
          //Read Title
          try {
            $title = $xmpReader->getTitle();
            if ($title) {
              $task->log("Title: {$title}");
              $bSuccess = true;
            }
          } catch (Exception $e) {
            $bWarning = true;
            $task->log("Error reading title, check the log for more info");
            Kohana_Log::add("error", "[XMP Test] Error reading title.\n" .
                            $e->getMessage() . "\n" . $e->getTraceAsString());
          }
          
          //Read Description
          try {
            $description = $xmpReader->getDescription();
            if ($description) {
              $task->log("Description: {$description}");
              $bSuccess = true;
            }
          } catch (Exception $e) {
            $bWarning = true;
            $task->log("Error reading description, check the log for more info");
            Kohana_Log::add("error", "[XMP Test] Error reading description.\n" .
                            $e->getMessage() . "\n" . $e->getTraceAsString());
          }
          
          //Read tags
          try {
            $tags = $xmpReader->getField("LastKeywordXMP", "http://ns.microsoft.com/photo/1.0");
            foreach ($tags as $tag) {
              $bSuccess = true;
              $task->log( t("Found tag: " . $tag));
            }
            $tags = $xmpReader->getField("LastKeywordXMP", "http://ns.microsoft.com/photo/1.0/");
            foreach ($tags as $tag) {
              $bSuccess = true;
              $task->log( t("Found tag: " . $tag));
            }
          } catch (Exception $e) {
            $bWarning = true;
            $task->log("Error reading tags, check the log for more info");
            Kohana_Log::add("error", "[XMP Test] Error reading tags.\n" .
                            $e->getMessage() . "\n" . $e->getTraceAsString());
          }
          
          //Read UserComments
          try {
            $comments = $xmpReader->getField("UserComment", "http://ns.adobe.com/exif/1.0/");
            foreach ($comments as $comment) {
              $bSuccess = true;
              $task->log( t("Found comment: " . $comment));
            }        
          } catch (Exception $e) {
            $bWarning = true;
            $task->log("Error reading comments, check the log for more info");
            Kohana_Log::add("error", "[XMP Test] Error reading comments.\n" .
                            $e->getMessage() . "\n" . $e->getTraceAsString());
          }
          
          //Read Faces
          try {
            
            $task->log( "Complete extracted XMP in XML form" );
            $task->log( $xmp->asXML());
          
            $xmpX = $xmpReader->getXPath();
            
            $task->log("\nLooking for Faces!");
            $rec = $xmpX->query("//MPRI:Regions/rdf:Bag");
            if( $rec->length ) {
              list( $bSuccess, $aRectangles, $aPeople ) = xmp_task::recursive_extract($task,$rec,0,false);
              if($bSuccess) {
                $task->log("found faces using recursive method!");
                for($i=0; $i < count($aPeople); $i++) {
                  $task->log("Found \"{$aPeople[$i]}\" at {{$aRectangles[$i]}} ");
                }
              }
            }
              
            /*$task->log("Trying to read //MPRI:Regions/rdf:Bag//rdf:Description");
            $mpreg = $xmpX->query("//MPRI:Regions/rdf:Bag//rdf:Description"); // the child element here should be rdf:bag
            
            $task->log("Found {$mpreg->length} elements. ");
            if($mpreg->length > 0) {
              for($i=0; $i < $mpreg->length; $i++) {//for each region that I have
                $task->log("Looking at {$mpreg->item($i)->nodeName}:{$mpreg->item($i)->nodeValue}");
                $regions = $mpreg->item($i)->childNodes;
                if($regions->length > 1) {//then we have both a region and a name
                  for($j=$regions->length-1; $j >= 0 ; $j--) {
                    $reg = $regions->item($j);
                    $task->log("  {$reg->nodeName} : {$reg->nodeValue}");
                  }
                }
              }
            } else { //that search wasn't successful, try again
              $task->log("Couldn't find any the usual way, so trying to read //MPRI:Regions/rdf:Bag/rdf:li");
              $li = $xmpX->query("//MPRI:Regions/rdf:Bag/rdf:li"); // the child element here should be rdf:bag
              $task->log("Found {$li->length} elements.");
              if($li->length) {
                for($i=0; $i < $li->length; $i++) {//for each region that I have
                  $regions = $li->item($i)->childNodes;// get the region children (there should be at most two)
                  $task->log("Found {$regions->length} 'regions' or 'descriptions'");
                  if($regions->length > 1) {//then we have both a region and a name
                    for($j=$regions->length-1; $j >= 0 ; $j--) {
                      $reg = $regions->item($j);
                      if( $reg->nodeName != "#text" ) {
                        $task->log("  {$reg->nodeName} : {$reg->nodeValue}");
                      }
                    }
                  }
                }
              }
            }*/

          } catch (Exception $e) {
            $bWarning = true;
            $task->log("Error reading faces, check the log for more info");
            Kohana_Log::add("error", "[XMP Test] Error reading faces.\n" .
                            $e->getMessage() . "\n" . $e->getTraceAsString());
          }     
        } // no error reading the XML


      } else {
        $bError = true;
        $task->log( t( "Class doesn't exist!" ) );
      }
    } else {
      $bError = true;
      $task->log( t("Could not find image file") );
    }
    $task->done = true;
    if($bError) {
      $task->state = "error";
      $task->status = "\"Catastrophic\" errors occured during run. Check log";
    } elseif ($bWarning) {
      $task->state = "error";
      $task->status = "Some warnings occured during run. Check log";
    } elseif ($bSuccess) {
      $task->state = "success";
      $task->status = "Success! Check log for extracted metadata.";
    } else {
      $task->state = "error";
      $task->status = "The task finished, but hard to tell what went on. Check the log!";
    }
    $task->percent_complete = 100;
  }
}
