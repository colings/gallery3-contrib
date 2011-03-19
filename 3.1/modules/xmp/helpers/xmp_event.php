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
class xmp_event_Core {
  static function item_created($item) {
    if ($item->is_photo()) {
      xmp::extract($item);
    }
  }
  static function module_change($changes) {
    // See if the Tags module is installed,
    //   tell the user to install it if it isn't.
    if (!module::is_active("tag") || in_array("tag", $changes->deactivate)) {
      site_status::warning(
        t("The XMP module requires at least the Tags module.  " .
          "<a href=\"%url\">Activate the Tags module now</a>",
          array("url" => url::site("admin/modules"))),
        "xmp_needs_tag");
    } else {
      site_status::clear("xmp_needs_tag");
    }
  }
}
