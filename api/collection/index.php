<?php

// Automatic Collection Updates can be performed using cURL:
// curl -b "skin=desktop;currenthost=Default;player_backend=mpd" http://localhost/rompr/api/collection/?rebuild > /dev/null
// where currenthost is the name of one of the Players defined in the Configuration menu
// and player_backend MUST be mpd or mopidy, depending on what your player is.
// You can also use eg -b "debug_enabled=8;currenthost=MPD;player_backend=mpd"
// to get more debug info in the webserver error log.

chdir('../..');

require_once ("includes/vars.php");
require_once ("includes/functions.php");
require_once ("backends/sql/backend.php");
$error = 0;

logger::trace("TIMINGS", "======================================================================");
$initmem = memory_get_usage();
logger::trace("COLLECTION", "Memory Used is ".$initmem);
$now2 = time();

switch (true) {

	case array_key_exists('item', $_REQUEST):
		logit('item');
		// Populate a dropdown in the collection or search results
		dumpAlbums($_REQUEST['item']);
		break;

	case array_key_exists('mpdsearch', $_REQUEST):
		logit('mpdsearch');
		// Handle an mpd-style search request
		require_once ("player/".$prefs['player_backend']."/player.php");
		require_once ("collection/collection.php");
		$trackbytrack = true;
		$doing_search = true;
		mpd_search();
		break;

	case array_key_exists('browsealbum', $_REQUEST):
		logit('browsealbum');
		// Populate a spotify album in mopidy's search results - as spotify doesn't return all tracks
		require_once ("player/".$prefs['player_backend']."/player.php");
		require_once ("collection/collection.php");
		$trackbytrack = true;
		$doing_search = true;
		browse_album();
		break;

	case array_key_exists("rawterms", $_REQUEST):
		logit('rawterms');
		// Handle an mpd-style search request requiring tl_track format results
		// Note that raw_search uses the collection models but not the database
		// hence $trackbytrack must be false
		logger::log("MPD SEARCH", "Doing RAW search");
		require_once ("player/".$prefs['player_backend']."/player.php");
		require_once ("collection/collection.php");
		require_once ("collection/dbsearch.php");
		$doing_search = true;
		raw_search();
		break;

	case array_key_exists('terms', $_REQUEST):
		logit('terms');
		// SQL database search request
		require_once ("player/".$prefs['player_backend']."/player.php");
		require_once ("collection/collection.php");
		require_once ("collection/dbsearch.php");
		$doing_search = true;
		database_search();
		break;

	case array_key_exists('rebuild', $_REQUEST):
		logit('rebuild');
		// This is a request to rebuild the music collection
		require_once ("player/".$prefs['player_backend']."/player.php");
		require_once ("collection/collection.php");
		$trackbytrack = true;
		update_collection();
		break;

	default:
		logger::warn("ALBUMS", "Couldn't figure out what to do!");
		break;

}

logger::trace("TIMINGS", "== Collection Update And Send took ".format_time(time() - $now2));
$peakmem = memory_get_peak_usage();
$ourmem = $peakmem - $initmem;
logger::trace("TIMINGS", "Peak Memory Used Was ".number_format($peakmem)." bytes  - meaning we used ".number_format($ourmem)." bytes.");
logger::trace("TIMINGS", "======================================================================");

function logit($key) {
	logger::log("COLLECTION", "Request is",$key,"=",$_REQUEST[$key]);
}

function checkDomains($d) {
	if (array_key_exists('domains', $d)) {
		return $d['domains'];
	}
	logger::trace("SEARCH", "No search domains in use");
	return false;
}

function mpd_search() {
	global $dbterms, $skin, $PLAYER_TYPE;
	// If we're searching for tags or ratings it would seem sensible to only search the database
	// HOWEVER - we could be searching for genre or performer or composer - which will not match in the database
	// For those cases ONLY, controller.js will call into this instead of database_search, and we set $dbterms
	// to make the collection check everything it finds against the database
	$cmd = $_REQUEST['command'];
	$domains = checkDomains($_REQUEST);
	foreach ($_REQUEST['mpdsearch'] as $key => $term) {
		switch ($key) {
			case 'tag':
			case 'rating':
				$dbterms[$key] = $term;
				break;

			// case 'any':
				// This makes a search term of 'Madness My Girl' into
				// search any Madness any My any Girl
				// which seems to produce better results with Spotify. But probably doesn't with Google Play, which
				// only uses the first term. Soundcloud concatenates them all back into one term again. What does MPD do?
				// foreach ($term as $t) {
				// 	$terms = explode(' ',$t);
				// 	foreach ($terms as $tom) {
				// 		$cmd .= " ".$key.' "'.format_for_mpd(html_entity_decode(trim($tom))).'"';
				// 	}
				// }
				// break;

			default:
				foreach ($term as $t) {
					$cmd .= " ".$key.' "'.format_for_mpd(html_entity_decode(trim($t))).'"';
				}
				break;

		}
	}
	logger::log("MPD SEARCH", "Search command : ".$cmd);
	if ($_REQUEST['resultstype'] == "tree") {
		require_once ("player/mpd/filetree.php");
		require_once ("skins/".$skin."/ui_elements.php");
		$player = new fileCollector();
		$player->doFileSearch($cmd, $domains);
	} else {
		cleanSearchTables();
		prepareCollectionUpdate();
		$collection = new musicCollection();
		$player = new $PLAYER_TYPE();
		$player->initialise_search();
		$player->populate_collection($cmd, $domains, $collection);
		foreach ($player->to_browse as $artist) {
			logger::log('MPD SEARCH', 'Browsing', $artist);
			$player->populate_collection('find file "'.$artist.'"', false, $collection);
		}
		$collection->tracks_to_database();
		close_transaction();
		dumpAlbums($_REQUEST['dump']);
		remove_findtracks();
	}
}

function browse_album() {
	global $PLAYER_TYPE, $skin, $prefs;
	$a = preg_match('/^(a|b)(.*?)(\d+|root)/', $_REQUEST['browsealbum'], $matches);
	if (!$a) {
		print '<h3>'.language::gettext("label_general_error").'</h3>';
		logger::error("DUMPALBUMS", "Browse Album Failed - regexp failed to match", $_REQUEST['browsealbum']);
		return false;
	}
	$why = $matches[1];
	$what = $matches[2];
	$who = $matches[3];
	$ad = get_album_details($who);
	logger::trace('BROWSEALBUM',$why,$what,$who,$ad[0]['Artistname']);
	$albumlink = get_albumlink($who);
	$sorter = choose_sorter_by_key($_REQUEST['browsealbum']);
	if (substr($albumlink, 0, 8) == 'podcast+') {
		require_once ('podcasts/podcastfunctions.php');
		logger::trace("ALBUMS", "Browsing For Podcast ".substr($albumlink, 9));
		$podid = getNewPodcast(substr($albumlink, 8), 0, false);
		logger::log("ALBUMS", "Ouputting Podcast ID ".$podid);
		outputPodcast($podid, false);
	} else {
		$player = new $PLAYER_TYPE();
		$collection = new musicCollection();
		$cmd = 'find file "'.$albumlink.'"';
		logger::log("MPD", "Doing Album Browse : ".$cmd);
		prepareCollectionUpdate();
		$player->populate_collection($cmd, false, $collection);
		$collection->tracks_to_database(true);
		close_transaction();
		remove_findtracks();
		// Just occasionally, the spotify album originally returned by search has an incorrect AlbumArtist
		// When we browse the album the new tracks therefore get added to a new album.
		// In this case we remove the old album and set the Albumindex of the new one to the Albumindex of the old one
		// (otherwise the GUI doesn't work)
		$lister = new $sorter($why.'album'.$who);
		$a = find_justadded_albums();
		if (is_array($a) && count($a) > 0 && $a[0] != $who) {
			logger::log('BROWSEALBUM', 'New album',$a[0],'was created. Setting it to',$who);
			if ($ad[0]['Image'] != null) {
				set_image_for_album($a[0], $ad[0]['Image']);
			}
			remove_album_from_database($who);
			generic_sql_query('UPDATE Albumtable SET Albumindex = '.$who.' WHERE Albumindex = '.$a[0]);
			generic_sql_query('UPDATE Tracktable SET Albumindex = '.$who.' WHERE Albumindex = '.$a[0]);
		}
		print $lister->output_track_list(true);
	}
}

function raw_search() {
	global $PLAYER_TYPE, $doing_search;
	$domains = checkDomains($_REQUEST);
	$collection = new musicCollection();
	$found = 0;
	logger::trace("MPD SEARCH", "checkdb is ".$_REQUEST['checkdb']);
	if ($_REQUEST['checkdb'] !== 'false') {
		logger::trace("MPD SEARCH", " ... checking database first ");
		$found = doDbCollection($_REQUEST['rawterms'], $domains, "RAW", $collection);
		if ($found > 0) {
			logger::log("MPD SEARCH", "  ... found ".$found." matches in database");
		}
	}
	if ($found == 0) {
		$cmd = $_REQUEST['command'];
		foreach ($_REQUEST['rawterms'] as $key => $term) {
			$cmd .= " ".$key.' "'.format_for_mpd(html_entity_decode($term[0])).'"';
		}
		logger::trace("MPD SEARCH", "Search command : ".$cmd);
		$doing_search = true;
		$player = new $PLAYER_TYPE();
		$player->populate_collection($cmd, $domains, $collection);

		// For backends that don't support multiple parameters (Google Play)
		// This'll return nothing for Spotify, so it's OK. It might help SoundCloud too.

		$cmd = $_REQUEST['command'].' any ';
		$parms = array();
		if (array_key_exists('artist', $_REQUEST['rawterms'])) {
			$parms[] = format_for_mpd(html_entity_decode($_REQUEST['rawterms']['artist'][0]));
		}
		if (array_key_exists('title', $_REQUEST['rawterms'])) {
			$parms[] = format_for_mpd(html_entity_decode($_REQUEST['rawterms']['title'][0]));
		}
		if (count($parms) > 0) {
			$cmd .= '"'.implode(' ',$parms).'"';
			logger::trace("MPD SEARCH", "Search command : ".$cmd);
			$doing_search = true;
			$collection->filter_duplicate_tracks();
			$player->populate_collection($cmd, $domains, $collection);
		}

	}
	print json_encode($collection->tracks_as_array());
}

function database_search() {
	$tree = null;
	$domains = checkDomains($_REQUEST);
	if ($_REQUEST['resultstype'] == "tree") {
		$tree = new mpdlistthing(null);
	} else {
		cleanSearchTables();
		open_transaction();
	 }
	$fcount = doDbCollection($_REQUEST['terms'], $domains, $_REQUEST['resultstype'], $tree);
	if ($_REQUEST['resultstype'] == "tree") {
		printFileSearch($tree, $fcount);
	} else {
		close_transaction();
		dumpAlbums($_REQUEST['dump']);
	}
}

function update_collection() {
	global $PLAYER_TYPE;

	// Check that an update is not currently in progress
	// and create the update lock if not
	if (collectionUpdateRunning()) {
		header('HTTP/1.1 500 Internal Server Error');
		print language::gettext('error_nocol');
		exit(0);
	}

	if (file_exists('prefs/monitor')) {
		unlink('prefs/monitor');
	}
	// Send some dummy data back to the browser, then close the connection
	// so that the browser doesn't time out and retry
	$sapi_type = php_sapi_name();
	logger::log('COLLECTION','SAPI Name is',$sapi_type);
	if (preg_match('/fpm/', $sapi_type) || preg_match('/fcgi/', $sapi_type)) {
		logger::mark('COLLECTION', 'Closing Request The FastCGI Way');
		print('<html></html>');
		fastcgi_finish_request();
	} else {
		logger::mark('COLLECTION', 'Closing Request The Apache Way');
		ob_end_clean();
		ignore_user_abort(true); // just to be safe
		ob_start();
		print('<html></html>');
		$size = ob_get_length();
		header("Content-Length: $size");
		header("Content-Encoding: none");
		header("Connection: close");
		ob_end_flush();
		ob_flush();
		flush();
		if (ob_get_contents()) {
			ob_end_clean();
		}
	}

	if (session_id()) {
		session_write_close();
	}

	// Browser is now happy. Now we can do our work in peace.
    logger::log('COLLECTION', 'Now were on our own');
    cleanSearchTables();
    logger::log('COLLECTION', 'Preparing update...');
    prepareCollectionUpdate();
    $player = new $PLAYER_TYPE();
    logger::log('COLLECTION', 'Doing Update');
    $player->musicCollectionUpdate();
    logger::log('COLLECTION', 'Tidying...');
    tidy_database();
    logger::log('COLLECTION', 'Finishing...');
    remove_findtracks();
	// Add a marker to the monitor file to say we've finished
	$player->collectionUpdateDone();
	// Clear the update lock
	clearUpdateLock();

}

?>
