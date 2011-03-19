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

/**
* This is the API for handling xmp data
*/
class xmp_Core {

  protected static $xmp_keys;

  static function extract($item) {
    $keys = array();
    // Only try to extract XMP from photos
    if ($item->mime_type == "image/jpeg") {
      require_once( MODPATH . "xmp/lib/Image/JpegXmpReader.php" );
      try {
        $xmpReader = new Image_JpegXmpReader( $item->file_path() );
        $xmp = $xmpReader->readXmp();
      } catch (Exception $e) {
         Kohana_Log::add("error", "[XMP Module] Error reading XML from {$item->title} at {$item->file_path()}.\n" .
                        $e->getMessage() . "\n" . $e->getTraceAsString());
      }
      if( $xmp === false) {
        message::warning("[XMP Module] Warning, couldn't read any XMP data from \"{$item->title}\". Please erase all current metadata, or ensure there is some, and try again.");
        Kohana_Log::add("warning", "[XMP Module] Warning, couldn't read any XMP data from \"{$item->title}\". Please erase all current metadata, or ensure there is some, and try again." );
      } else {//end of if XMP readable
        try {
          $title = $xmpReader->getTitle();
          if ($title && $item->title != $title) {//check if the title has changed!
            $item->title = $title;
            $item->slug = preg_replace("/[^A-Za-z0-9-_]+/", "-", $title); //change the slug to be a friendly URL
            $base_slug = $item->slug;
            while (ORM::factory("item")
                   ->where("parent_id", "=", $item->parent_id)
                   ->and_open()
                   ->where("slug", "=", $item->slug)
                   ->close()
                   ->find()->id) {
              $rand = rand();
              $item->slug = "$base_slug-$rand";
            }
          }
        } catch (Exception $e) {
          Kohana_Log::add("error", "[XMP Module] Error adding title.\n" .
                          $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        try {
          $description = $xmpReader->getDescription();
          if ($description) {
            $item->description = $description;
          } else {
            $caption = $xmpReader->getImplodedField("UserComment", "http://ns.adobe.com/exif/1.0/");
            if($caption) {
              if(strlen($caption) > 2040 ) { // the maximum field length is 2048, so just check for too long!
                $caption = substr($caption, 0, 2040);
              }
              $item->description = $caption;
            }
          }
        } catch (Exception $e) {
          Kohana_Log::add("error", "[XMP Module] Error adding the description.\n" .
                          $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        if( module::is_active("tag") ) { //tag module installed, so deal with tags!
          try {
            $currentTags = tag::item_tags($item);
            $currentTagNames = array();
            foreach ($currentTags as $tagObject) {
              $currentTagNames[$tagObject->name] = $tagObject->id;
            }
            $toAdd = array();

            //Start by looking at tags here
            $tags = $xmpReader->getField("LastKeywordXMP", "http://ns.microsoft.com/photo/1.0/");
            foreach( $tags as $unsplitTag ) {
              $splitTags =  explode('/', $unsplitTag);
              foreach ($splitTags as $tag) {
                $toAdd[$tag] = 0;
              }
            }

            //Surprisingly, this is a different search
            $tags = $xmpReader->getField("LastKeywordXMP", "http://ns.microsoft.com/photo/1.0");
            foreach( $tags as $unsplitTag ) {
              $splitTags =  explode('/', $unsplitTag);
              foreach ($splitTags as $tag) {
                $toAdd[$tag] = 0;
              }
            }

            $tags = array_merge( $toAdd, $currentTagNames );
           
            if($tags !== false) {
              foreach ($tags as $tag=>$value) {
                if( !$value ) {
                  $addedTag = tag::add($item, $tag);
                  $tags[$tag] = $addedTag->id;
                }
              } //end of foreach tag
            } //end of if there are tags
            $tagArray = implode($addedtags);
          } catch (Exception $e) {
            Kohana_Log::add("error", "[XMP Module] Error adding tag: $tag\n" .
                        $e->getMessage() . "\n" . $e->getTraceAsString());
          }

          if ( (module::is_active("tagfaces") && method_exists('tagfaces','add') )
                  || ( module::is_active("photoannotation") && method_exists('photoannotation','saveface') ) ) {
            try {
              $xmpX = $xmpReader->getXPath();
              $rec = $xmpX->query("//MPRI:Regions/rdf:Bag");
              if( $rec->length ) {
                list( $bSuccess, $aRectangles, $aPeople ) = xmp::recursive_extract($rec,false);
              }
              if( $bSuccess && ( count($aRectangles) == count($aPeople) ) ){
                $annotation_id = array();
                foreach($aPeople as $person) {
                  if( !array_key_exists($person, $tags) ) {
                    //if the person doesn't exist as a tag, then add them as a tag first
                    //add the tags here so I can use them when saving face annotations as a face.
                    $addedTag = tag::add($item, $person);
                    $tags[$person] = $addedTag->id;
                    $annotation_id[$person] = "";
                  } else {
                    ////if the person already exists as a tag, then see if the
                    //annotation is also present. If it is, update the annotation
                    $existingFace = ORM::factory("items_face")
                       ->where("tag_id", "=", $tags[$person])
                       ->and_where("item_id", "=", $item->id)
                       ->find_all(1);
                    if( count($existingFace) > 0 ) {
                      $annotation_id[$person] = $existingFace->id;
                    } else {
                      $annotation_id[$person] = "";
                    }
                  }
                }


                $defaultSize = module::get_var("gallery", "resize_size");
                $defDimensions = $item->scale_dimensions($defaultSize);
                if($item->height <= $defDimensions[0]) {
                  $width = $item->width;
                  $height = $item->height;
                } else {
                  $width = $defDimensions[1];
                  $height = $defDimensions[0];
                }
                for($i = 0; $i < count($aPeople); ++$i){
                  $aRectMSFT = explode(", ",$aRectangles[$i]);
                  //I need to get the default size for images on this installation.

                  $x =  $aRectMSFT[0] * $width;
                  $y =  $aRectMSFT[1] * $height;
                  $dx = $aRectMSFT[2] * $width;
                  $dy = $aRectMSFT[3] * $height;

                  if( $dx < 0.05 || $dy < 0.05 ) { //the face square will be small
                    $addX = 0.05 * $width;
                    $addY = 0.05 * $height;
                    $x1 = floor( $x - ( $addX / 2 ) );
                    $x2 = floor( $x + ( $addX / 2 ) );
                    $y1 = floor( $y - ( $addY / 2 ) );
                    $y2 = floor( $y + ( $addY / 2 ) );
                  } else {
                    $x1 = floor( $x );
                    $x2 = floor( $x + $dx );
                    $y1 = floor( $y );
                    $y2 = floor( $y + $dy );
                  }

                  if ( $x1 < 0 ) { $x1 = 0; }
                  if ( $x2 > $width ) { $x2 = $width; }
                  if ( $y1 < 0 ) { $y1 = 0; }
                  if ( $y2 > $height ) { $y2 = $height; }

                  if(module::is_active("photoannotation") && method_exists('photoannotation','saveface')) {
                    photoannotation::saveface($tags[$aPeople[$i]],$item->id,$x1,$y1,$x2,$y2,"",$annotation_id[$aPeople[$i]]);
                    //photoannotation::add($item,$aPeople[$i],"",$x1, $y1, $x2, $y2, TRUE);
                  } elseif ( module::is_active("tagfaces") && method_exists('tagfaces','add') ) {
                    tagfaces::add($item,$aPeople[$i],"",$x1, $y1, $x2, $y2, TRUE);
                  }
                }//end of for faces

              }
            } catch (Exception $e) {
              Kohana_Log::add("error", "[XMP Module] Error adding tagface: $tag\n" .
                          $e->getMessage() . "\n" . $e->getTraceAsString());
            }
          } else {//end of if tagfaces
            if ( !method_exists('tagfaces','add') ) {
              Kohana_Log::add("error", "[XMP Module] There is no \"add\" function in the tagfaces module");
            }
            if ( !method_exists('photoannotation','saveface') ) {
              Kohana_Log::add("error", "[XMP Module] There is no \"add\" function in the photoannotation module");
            }
          }
        }//end of if tag
        $item->save();
      } 
    }//end of if photo()
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

  static function recursive_extract($baseNode,$bFaces) {
    $bSuccess = false;
    $aRectangles = array();
    $aPeople = array();

    if( $bFaces ) { //previous level told us we have children, so extract them!
      $bSuccess = true;
      for($i=0; $i < $baseNode->length; $i++) { //for each item in the DOMNodeList ($baseNode)
        $reg = $baseNode->item($i);
        if( $reg->nodeName == "MPReg:PersonDisplayName" ) {
          $aPeople[] = $reg->nodeValue;
        } elseif ( $reg->nodeName == "MPReg:Rectangle" ) {
          $aRectangles[] = $reg->nodeValue;
        }
      }
    } else {
      //baseNode is a DOMNodeList, so whatever I do, I need to check that it has a length
      if ( $baseNode->length > 0 ) {// if there is a length, I need to go through my usual checks
        for($i=0; $i < $baseNode->length; $i++) { //for each item in the DOMNodeList ($baseNode)
          $node = $baseNode->item($i);
          //check for attributes
          if( $node->hasAttributes() ){
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
                $aRectangles[] = $rectangle;
                $aPeople[] = $person;
                $bSuccess = true;
              }
            }
          }

          //check the children
          if ($node->hasChildNodes() ) {
            $childNodes = $node->childNodes;
            $bFaces = xmp::check_children_for_faces($childNodes);
            if( $bFaces ) {
              //$task->log("  Next level down has faces!");
              list( $bTmpSuccess, $aTmpRectangles, $aTmpPeople ) = xmp::recursive_extract($childNodes,true);
            } else {
              list( $bTmpSuccess, $aTmpRectangles, $aTmpPeople ) = xmp::recursive_extract($childNodes,false);
            }
            $bSuccess += $bTmpSuccess;
            $aRectangles = array_merge($aRectangles, $aTmpRectangles);
            $aPeople = array_merge($aPeople, $aTmpPeople);
          }
        } //on of for loop (over each item in DOMNodeList)
      }
    }

    $aReturn = array($bSuccess, $aRectangles, $aPeople);
    return $aReturn;
  }

  
  static function check_config() {
    if(xmp::file_exists_ip("System.php")){
      require_once "System.php";
      site_status::clear("xmp_pear");
    } else {
      site_status::error(
        t("The XMP module requires PEAR to be installed and in a location that is in the include path. Unfortunately, this does not seem to be the case. include_path: " . get_include_path()),
        "xmp_pear");    
    }
    
    if(xmp::file_exists_ip(MODPATH . "xmp/lib/Image/JpegXmpReader.php") || file_exists(MODPATH . "xmp/lib/Image/JpegXmpReader.php") ){
      require_once( MODPATH . "xmp/lib/Image/JpegXmpReader.php" );
      site_status::clear("xmp_jpeg");
    } else {
      site_status::error(
        t("The XMP module could not find the required additional library JpegXmpReader in the 'lib/Image' folder of the module."),
        "xmp_jpeg");      
    }

  }

  static function file_exists_ip($filename) {
    if(function_exists("get_include_path")) {
      $include_path = get_include_path();
    } elseif(false !== ($ip = ini_get("include_path"))) {
      $include_path = $ip;
    } else {return false;}

    if(false !== strpos($include_path, PATH_SEPARATOR)) {
      if(false !== ($temp = explode(PATH_SEPARATOR, $include_path)) && count($temp) > 0) {
        for($n = 0; $n < count($temp); $n++) {
          if(false !== @file_exists($temp[$n] . DIRECTORY_SEPARATOR .  $filename)) {
            return true;
          }
        }
        return false;
      } else {return false;}
    } elseif(!empty($include_path)) {
      if(false !== @file_exists($include_path . DIRECTORY_SEPARATOR .  $filename)) {
        return true;
      } else {return false;}
    } else {return false;}
  }

}
?>
