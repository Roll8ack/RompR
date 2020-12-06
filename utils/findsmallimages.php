<?php
chdir('..');
include ("includes/vars.php");
include ("includes/functions.php");
include ("backends/sql/backend.php");
$results = array();
$r = generic_sql_query('SELECT Image, ImgKey FROM Albumtable', false, PDO::FETCH_OBJ);
foreach ($r as $obj) {
	$image = new baseAlbumImage(array('baseimage' => $obj->Image));
	if ($image->is_collection_image()) {
		$images = $image->get_images();
		$ih = new imageHandler($images['asdownloaded']);
		$size = $ih->get_image_dimensions();
		// We return -1 for SVG files, since they don't really have a size
		if ($size['width'] > -1) {
			if ($size['height'] < 300) {
				// Should catch all the old small images we downloaded from Last.FM
				logger::log("ALBUMART", "Image ".$obj->Image." is too small : ".$size['width'].'x'.$size['height']);
				array_push($results, $obj->ImgKey);
			}
		}
		$ih->destroy();
	}
}
header('Content-Type: application/json; charset=utf-8');
print json_encode($results);

?>
