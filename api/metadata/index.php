<?php
chdir('../..');
require_once ("includes/vars.php");
require_once ("includes/functions.php");
logger::mark("USERRATING", "--------------------------START---------------------");

$error = 0;
$count = 1;
$download_file = "";

prefs::$database = new metaDatabase();
prefs::$database->open_transaction();

$params = json_decode(file_get_contents('php://input'), true);

// If you add new actions remember to update actions_requring_cleanup in metahandlers.js

foreach($params as $p) {

	logger::info("METADATA", "Doing action", $p['action']);
	foreach ($p as $i => $v) {
		logger::trace("Parameter", $i,'=',$v);
	}

	prefs::$database->sanitise_data($p);

	switch ($p['action']) {

		// Things that return information about modified items
		case 'set':
		// case 'seturi':
		// case "add":
		case 'inc':
		case 'remove':
		case 'cleanup':
		case 'amendalbum':
		case 'deletealbum':
		case 'setasaudiobook':
		case 'usetrackimages':
		case 'delete':
		case 'deletewl':
		case 'deleteid':
		case 'resetresume':
		case 'addalbumtocollection':
		case 'findandset':
			prefs::$database->create_foundtracks();
			prefs::$database->{$p['action']}($p);
			prefs::$database->prepare_returninfo();
			break;

		case 'browsesearchresult':
			prefs::$database->check_album_browse($p['albumindex']);
			prefs::$database->prepare_returninfo();
			break;

		case 'ui_wakeup_refresh':
			$albums = [];
			foreach ($p['albums'] as $album) {
				if (preg_match('/[abz]album(\d+)/', $album, $matches)) {
					$albums[] = $matches[1];
				} else {
					logger::error('UIWAKEUP', "Couldn't match album", $album);
				}
			}
			prefs::$database->create_foundtracks();
			prefs::$database->dummy_returninfo($albums);
			prefs::$database->prepare_returninfo();
			break;

		case 'getreturninfo':
			prefs::$database->prepare_returninfo();
			break;

		case 'youtubedl':
		case 'youtubedl_album':
			set_time_limit(0);
			prefs::$database->close_transaction();
			prefs::$database->create_foundtracks();
			$progress_file = prefs::$database->{$p['action']}($p);
			prefs::$database->prepare_returninfo();
			unlink($progress_file);
			$progress_file = $progress_file.'_result';
			file_put_contents($progress_file, json_encode(prefs::$database->returninfo));
			exit(0);
			break;


		// Things that return information but do not modify items
		case 'get':
		case 'findandreturn':
			prefs::$database->{$p['action']}($p);
			break;

		case 'findandreturnall':
			prefs::$database->{$p['action']}($p);
			exit(0);
			break;

		// Things that do not return information
		case 'setalbummbid':
		case 'clearwishlist':
		case 'ban':
			prefs::$database->{$p['action']}($p);
			break;

		default:
			logger::warn("USERRATINGS", "Unknown Request",$p['action']);
			http_response_code(400);
			break;
	}
	prefs::$database->check_transaction();
}

prefs::$database->close_transaction();
print json_encode(prefs::$database->returninfo);

logger::mark("USERRATING", "---------------------------END----------------------");

?>
