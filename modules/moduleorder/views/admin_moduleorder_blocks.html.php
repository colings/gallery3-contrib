<?php defined("SYSPATH") or die("No direct script access.") ?>
<? $indent_provider = module::get_var("gallery", "identity_provider") ?>
<? foreach ($modules as $ref => $text): ?>
<? if ($text == "gallery" || $text == $indent_provider): ?>
<li style="background-color:#A8A8A8; margin:0.5em; padding:0.3em 0.8em;" ref="<?= $ref ?>"><?= $text ?></li>
<? else: ?>
<li class="g-draggable" ref="<?= $ref ?>"><?= $text ?></li>
<? endif ?>
<? endforeach ?>
