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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class editcaptured_task_Core {
  static function available_tasks() {
    return array(Task_Definition::factory()
                 ->callback("editcaptured_task::update_captured")
                 ->name(t("Update all album captured dates"))
                 ->description(t("Updates all album captured dates to that of the youngest child"))
                 ->severity(log::SUCCESS));
  }

  static function update_captured($task) {

    $start = microtime(true);

    // Figure out the total number of albums in the database.
    // If this is the first run, also set last_id and completed to 0.
    $total = $task->get("total");
    if (empty($total)) {
      $task->set("total", $total = count(ORM::factory("item")->where("type", "=", "album")->find_all()));
      $task->set("last_id", 0);
      $task->set("completed", 0);
      $task->set("updated", 0);
    }
    $last_id = $task->get("last_id");
    $completed = $task->get("completed");
    $updated = $task->get("updated");

    // Check each album in the database to see if its captured date is correct
    // If it isn't, update it.
    foreach (ORM::factory("item")
             ->where("id", ">", $last_id)
             ->where("type", "=", "album")
             ->find_all(100) as $item) {

      // We don't want to allow changes of the root album
      if ($item->id != 1) {
        // Find the date of the youngest child
        $model = ORM::factory("item")
	               ->where("parent_id", "=", $item->id)
                   ->where("captured", "IS NOT", null)
	               ->order_by("captured", "DESC");
        $first_child = $model->find();

        // If we found the youngest child and it doesn't match our date, update it
	    if ($first_child->id and $item->captured != $first_child->captured) {
          $item->captured = $first_child->captured;
		  $item->save();
		  $updated++;
        }
      }

      $last_id = $item->id;
      $completed++;

      if ($completed == $total || microtime(true) - $start > 1.5) {
        break;
      }
    }

    $task->set("completed", $completed);
    $task->set("last_id", $last_id);
    $task->set("updated", $updated);

    if ($total == $completed) {
      $task->done = true;
      $task->state = "success";
      $task->percent_complete = 100;
    } else {
      $task->percent_complete = round(100 * $completed / $total);
    }
    $task->status = t("%completed / %total albums scanned, %updated updated", array("completed" => $completed, "total" => $total, "updated" => $updated));
  }
}
