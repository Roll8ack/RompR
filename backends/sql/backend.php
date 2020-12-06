<?php

include ("backends/sql/connect.php");
require_once ("skins/".$skin."/ui_elements.php");
connect_to_database($romonitor_hack);
$find_track = null;
$update_track = null;
$transaction_open = false;
$numdone = 0;
$doing_search = false;
$nodata = array (
	'isSearchResult' => 4,
	'Rating' => 0,
	'Tags' => array()
);

// So what are Hidden tracks?
// These are used to count plays from online sources when those tracks are not in the collection.
// Doing this does increase the size of the database. Quite a lot. But without it the stats for charts
// and fave artists etc don't make a lot of sense in a world where a proportion of your listening
// is in response to searches of Spotify or youtube etc.

// Wishlist items have Uri as NULL. Each wishlist track is in a distinct album - this makes stuff
// easier for the wishlist viewer

// Assumptions are made in the code that Wishlist items will not be hidden tracks and that hidden
// tracks have no metadata apart from a Playcount. Always be aware of this.

// For tracks, LastModified controls whether a collection update will update any of its data.
// Tracks added by hand (by tagging or rating, via userRatings.php) must have LastModified as NULL
// - this is how we prevent the collection update from removing them.

// Search:
// Tracktable.isSearchResult is set to:
//		1 on any existing track that comes up in the search
//		2 for any track that comes up the search and has to be added - i.e it's not part of the main collection.
//		3 for any hidden track that comes up in search so it can be re-hidden later.
//		Note that there is arithmetical logic to the values used here, they're not arbitrary flags

// Collection:
//  justAdded is automatically set to 1 for any track that has just been added
//  when updating the collection we set them all to 0 and then set to 1 on any existing track we find,
//  then we can easily remove old tracks.

function create_new_track(&$data) {

	// create_new_track
	//		Creates a new track, along with artists and album if necessary
	//		Returns: TTindex

	global $mysqlc;

	if ($data['albumai'] == null) {
		// Does the albumartist exist?
		$data['albumai'] = check_artist($data['albumartist']);
	}

	// Does the track artist exist?
	if ($data['trackai'] == null) {
		if ($data['artist'] != $data['albumartist']) {
			$data['trackai'] = check_artist($data['artist']);
		} else {
			$data['trackai'] = $data['albumai'];
		}
	}

	if ($data['albumai'] == null || $data['trackai'] == null) {
		logger::warn('BACKEND', "Trying to create new track but failed to get an artist index");
		return null;
	}

	if ($data['albumindex'] == null) {
		// Does the album exist?
		if ($data['album'] == null) {
			$data['album'] = 'rompr_wishlist_'.microtime('true');
		}
		$data['albumindex'] = check_album($data);
		if ($data['albumindex'] == null) {
			logger::warn('BACKEND', "Trying to create new track but failed to get an album index");
			return null;
		}
	}

	$data['sourceindex'] = null;
	if ($data['uri'] === null && array_key_exists('streamuri', $data) && $data['streamuri'] !== null) {
		$data['sourceindex'] = check_radio_source($data);
	}

	if (sql_prepare_query(true, null, null, null,
		"INSERT INTO
			Tracktable
			(Title, Albumindex, Trackno, Duration, Artistindex, Disc, Uri, LastModified, Hidden, isSearchResult, Sourceindex, isAudiobook, Genreindex, TYear)
			VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			$data['title'],
			$data['albumindex'],
			$data['trackno'],
			$data['duration'],
			$data['trackai'],
			$data['disc'],
			$data['uri'],
			$data['lastmodified'],
			$data['hidden'],
			$data['searchflag'],
			$data['sourceindex'],
			$data['isaudiobook'],
			$data['genreindex'],
			$data['year']
		))
	{
		return $mysqlc->lastInsertId();
	}
	return null;
}

function check_radio_source($data) {
	global $mysqlc;
	$index = simple_query('Sourceindex', 'WishlistSourcetable', 'SourceUri', $data['streamuri'], null);
	if ($index === null) {
		logger::log('BACKEND', "Creating Wishlist Source",$data['streamname']);
		if (sql_prepare_query(true, null, null, null,
		"INSERT INTO WishlistSourcetable (SourceName, SourceImage, SourceUri) VALUES (?, ?, ?)",
		$data['streamname'], $data['streamimage'], $data['streamuri']))
		{
			$index = $mysqlc->lastInsertId();
		}
	}
	return $index;
}

function check_artist($artist) {

	// check_artist:
	//		Checks for the existence of an artist by name in the Artisttable and creates it if necessary
	//		Returns: Artistindex

	$index = sql_prepare_query(false, null, 'Artistindex', null, "SELECT Artistindex FROM Artisttable WHERE LOWER(Artistname) = LOWER(?)", $artist);
	if ($index === null) {
		$index = create_new_artist($artist);
	}
	return $index;
}

function create_new_artist($artist) {

	// create_new_artist
	//		Creates a new artist
	//		Returns: Artistindex

	global $mysqlc;
	$retval = null;
	if (sql_prepare_query(true, null, null, null, "INSERT INTO Artisttable (Artistname) VALUES (?)", $artist)) {
		$retval = $mysqlc->lastInsertId();
		logger::trace('BACKEND', "Created artist",$artist,"with Artistindex",$retval);
	}
	return $retval;
}

function best_value($a, $b) {

	// best_value
	//		Used by check_album to determine the best value to use when updating album details
	//		Returns: value

	if ($b == null || $b == "") {
		return $a;
	} else {
		return $b;
	}
}

function check_album(&$data) {

	// check_album:
	//		Checks for the existence of an album and creates it if necessary
	//		Returns: Albumindex

	global $prefs, $trackbytrack, $doing_search;
	$index = null;
	$year = null;
	$img = null;
	$mbid = null;
	$obj = null;
	$otherobj = null;

	$result = sql_prepare_query(false, PDO::FETCH_OBJ, null, null,
		"SELECT
			Albumindex,
			Year,
			Image,
			AlbumUri,
			mbid
		FROM
			Albumtable
		WHERE
			LOWER(Albumname) = LOWER(?)
			AND AlbumArtistindex = ?
			AND Domain = ?", $data['album'], $data['albumai'], $data['domain']);
	$obj = array_shift($result);

	if ($prefs['preferlocalfiles'] && $trackbytrack && !$doing_search && $data['domain'] == 'local' && !$obj) {
		// Does the album exist on a different, non-local, domain? The checks above ensure we only do this
		// during a collection update
		$result = sql_prepare_query(false, PDO::FETCH_OBJ, null, null,
			"SELECT
				Albumindex,
				Year,
				Image,
				AlbumUri,
				mbid,
				Domain
			FROM
				Albumtable
			WHERE
				LOWER(Albumname) = LOWER(?)
				AND AlbumArtistindex = ?", $data['album'], $data['albumai']);
		$obj = array_shift($result);
		if ($obj) {
			logger::mark('BACKEND', "Album ".$data['album']." was found on domain ".$obj->Domain.". Changing to local");
			$index = $obj->Albumindex;
			if (sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET AlbumUri=NULL, Domain=?, justUpdated=? WHERE Albumindex=?", 'local', 1, $index)) {
				$obj->AlbumUri = null;
				logger::debug('BACKEND', "   ...Success");
			} else {
				logger::warn('BACKEND', "   Album ".$data['album']." update FAILED");
				return false;
			}
		}
	}

	if ($obj) {
		$index = $obj->Albumindex;
		$year = best_value($obj->Year, $data['date']);
		$img  = best_value($obj->Image, $data['image']);
		$uri  = best_value($obj->AlbumUri, $data['albumuri']);
		$mbid  = best_value($obj->mbid, $data['ambid']);
		if ($year != $obj->Year || $img != $obj->Image || $uri != $obj->AlbumUri || $mbid != $obj->mbid) {

			if ($prefs['debug_enabled'] > 6) {
				logger::mark('BACKEND', "Updating Details For Album ".$data['album']." (index ".$index.")" );
				logger::log('BACKEND', "  Old Date  : ".$obj->Year);
				logger::log('BACKEND', "  New Date  : ".$year);
				logger::log('BACKEND', "  Old Image : ".$obj->Image);
				logger::log('BACKEND', "  New Image : ".$img);
				logger::log('BACKEND', "  Old Uri  : ".$obj->AlbumUri);
				logger::log('BACKEND', "  New Uri  : ".$uri);
				logger::log('BACKEND', "  Old MBID  : ".$obj->mbid);
				logger::log('BACKEND', "  New MBID  : ".$mbid);
			}

			if (sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET Year=?, Image=?, AlbumUri=?, mbid=?, justUpdated=1 WHERE Albumindex=?",$year, $img, $uri, $mbid, $index)) {
				logger::debug('BACKEND', "   ...Success");
			} else {
				logger::warn('BACKEND', "   Album ".$data['album']." update FAILED");
				return false;
			}
		}
	} else {
		$index = create_new_album($data);
	}
	return $index;
}

function create_new_album($data) {

	// create_new_album
	//		Creates an album
	//		Returns: Albumindex

	global $mysqlc;
	$retval = null;
	$im = array(
		'searched' => $data['image'] ? 1: 0,
		'image' => $data['image']
	);
	if (sql_prepare_query(true, null, null, null,
		"INSERT INTO
			Albumtable
			(Albumname, AlbumArtistindex, AlbumUri, Year, Searched, ImgKey, mbid, Domain, Image)
		VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?)",
		$data['album'], $data['albumai'], $data['albumuri'], $data['date'], $im['searched'], $data['imagekey'], $data['ambid'], $data['domain'], $im['image'])) {
		$retval = $mysqlc->lastInsertId();
		logger::trace('BACKEND', "Created Album ".$data['album']." with Albumindex ".$retval);
	}
	return $retval;
}

function remove_ttid($ttid) {

	// Remove a track from the database.
	// Doesn't do any cleaning up - call remove_cruft afterwards to remove orphaned artists and albums

	// Deleting tracks will delete their associated playcounts. While it might seem like a good idea
	// to hide them instead, in fact this results in a situation where we have tracks in our database
	// that no longer exist in physical form - eg if local tracks are removed. This is really bad if we then
	// later play those tracks from an online source and rate them. romprmetadata::find_item will return the hidden local track,
	// which will get rated and appear back in the collection. So now we have an unplayable track in our collection.
	// There's no real way round it, (without creating some godwaful lookup table of backends it's safe to do this with)
	// so we just delete the track and lose the playcount information.

	// If it's a search result, it must be a manually added track (we can't delete collection tracks)
	// and we might still need it in the search, so set it to a 2 instead of deleting it.
	// Also in this case, set isAudiobook to 0 because if it's a search result AND it's been moved to Spoken Word
	// then deleted, if someone tried to then re-add it it doesn't appear in the display because all manually-added tracks go to
	// the Collection not Spoken Word, but this doesn't work oh god it's horrible just leave it.

	logger::log('BACKEND', "Removing track ".$ttid);
	$result = false;
	if (generic_sql_query("DELETE FROM Tracktable WHERE isSearchResult != 1 AND TTindex = '".$ttid."'",true)) {
		if (generic_sql_query("UPDATE Tracktable SET isSearchResult = 2, isAudiobook = 0 WHERE isSearchResult = 1 AND TTindex = '".$ttid."'", true)) {
			$result = true;;
		}
	}
	return $result;
}

function list_tags() {

	// list_tags
	//		Return a sorted lst of tag names. Used by the UI for creating the tag menu

	$tags = array();
	$result = generic_sql_query("SELECT Name FROM Tagtable ORDER BY LOWER(Name)");
	foreach ($result as $r) {
		$tags[] = $r['Name'];
	}
	return $tags;
}

function list_genres() {
	return sql_get_column("SELECT Genre FROM Genretable ORDER BY Genre ASC", 'Genre');
}

function list_artists() {
	global $prefs;

	$qstring = "SELECT DISTINCT Artistname FROM Tracktable JOIN Artisttable USING (Artistindex)
		WHERE (LinkChecked = 0 OR LinkChecked = 2) AND isAudiobook = 0 AND isSearchResult < 2 AND Hidden = 0 AND Uri IS NOT NULL
		ORDER BY ";
	foreach ($prefs['artistsatstart'] as $a) {
		$qstring .= "CASE WHEN LOWER(Artistname) = LOWER('".$a."') THEN 1 ELSE 2 END, ";
	}
	if (count($prefs['nosortprefixes']) > 0) {
		$qstring .= "(CASE ";
		foreach($prefs['nosortprefixes'] AS $p) {
			$phpisshitsometimes = strlen($p)+2;
			$qstring .= "WHEN LOWER(Artistname) LIKE '".strtolower($p).
				" %' THEN LOWER(SUBSTR(Artistname,".$phpisshitsometimes.")) ";
		}
		$qstring .= "ELSE LOWER(Artistname) END)";
	} else {
		$qstring .= "LOWER(Artistname)";
	}
	return sql_get_column($qstring, 'Artistname');
}

function list_albumartists() {
	$artists = array();
	$sorter = new sortby_artist('aartistroot');
	foreach ($sorter->root_sort_query() as $a) {
		$artists[] = $a['Artistname'];
	}
	return $artists;
}

function clear_wishlist() {
	return generic_sql_query("DELETE FROM Tracktable WHERE Uri IS NULL", true);
}

function num_collection_tracks($albumindex) {
	// Returns the number of tracks this album contains that were added by a collection update
	// (i.e. not added manually). We do this because editing year or album artist for those albums
	// won't hold across a collection update, so we just forbid it.
	return generic_sql_query("SELECT COUNT(TTindex) AS cnt FROM Tracktable WHERE Albumindex = ".$albumindex." AND LastModified IS NOT NULL AND Hidden = 0 AND Uri IS NOT NULL AND isSearchResult < 2", false, null, 'cnt', 0);

}

function album_is_audiobook($albumindex) {
	// Returns the maxiumum value of isAudiobook for a given album
	return generic_sql_query("SELECT MAX(isAudiobook) AS cnt FROM Tracktable WHERE Albumindex = ".$albumindex." AND Hidden = 0 AND Uri IS NOT NULL AND isSearchResult < 2", false, null, 'cnt', 0);
}

function get_all_data($ttid) {

	// Misleadingly named function which should be used to get ratings and tags
	// (and whatever else we might add) based on a TTindex
	global $nodata;
	$data = $nodata;
	$result = generic_sql_query("SELECT
			IFNULL(r.Rating, 0) AS Rating,
			IFNULL(p.Playcount, 0) AS Playcount,
			".sql_to_unixtime('p.LastPlayed')." AS LastTime,
			".sql_to_unixtime('tr.DateAdded')." AS DateAdded,
			IFNULL(".SQL_TAG_CONCAT.", '') AS Tags,
			tr.isSearchResult,
			tr.Hidden
		FROM
			Tracktable AS tr
			LEFT JOIN Ratingtable AS r ON tr.TTindex = r.TTindex
			LEFT JOIN Playcounttable AS p ON tr.TTindex = p.TTindex
			LEFT JOIN TagListtable AS tl ON tr.TTindex = tl.TTindex
			LEFT JOIN Tagtable AS t USING (Tagindex)
		WHERE tr.TTindex = ".$ttid."
		GROUP BY tr.TTindex
		ORDER BY t.Name"
	);
	if (count($result) > 0) {
		$data = array_shift($result);
		$data['Tags'] = ($data['Tags'] == '') ? array() : explode(', ', $data['Tags']);
		if ($data['LastTime'] != null && $data['LastTime'] != 0 && $data['LastTime'] != '0') {
			$data['Last'] = $data['LastTime'];
		}
	}
	return $data;
}

// Looking up this way is hugely faster than looking up by Uri
function get_extra_track_info(&$filedata) {
	$data = array();;
	$result = sql_prepare_query(false, PDO::FETCH_ASSOC, null, null,
		'SELECT Uri, TTindex, Disc, Artistname AS AlbumArtist, Albumtable.Image AS "X-AlbumImage", mbid AS MUSICBRAINZ_ALBUMID, Searched, IFNULL(Playcount, 0) AS Playcount, isAudiobook
			FROM
				Tracktable
				JOIN Albumtable USING (Albumindex)
				JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex
				LEFT JOIN Playcounttable USING (TTindex)
				WHERE Title = ?
				AND TrackNo = ?
				AND Albumname = ?',
			$filedata['Title'], $filedata['Track'], $filedata['Album']
	);
	foreach ($result as $tinfo) {
		if ($tinfo['Uri'] == $filedata['file']) {
			if ($tinfo['isAudiobook'] > 0) {
				$tinfo['type'] = 'audiobook';
			}
			$tinfo['isAudiobook'] = null;
			$data = array_filter($tinfo, function($v) {
				if ($v === null || $v == '') {
					return false;
				}
				return true;
			});
			break;
		}
	}
	if (count($data) == 0) {
		$result = sql_prepare_query(false, PDO::FETCH_ASSOC, null, null,
			'SELECT Albumtable.Image AS "X-AlbumImage", mbid AS MUSICBRAINZ_ALBUMID, Searched
				FROM
					Albumtable
					JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex
					WHERE Albumname = ?
					AND Artistname = ?',
				$filedata['Album'], concatenate_artist_names($filedata['AlbumArtist'])
		);
		foreach ($result as $tinfo) {
			$data = array_filter($tinfo, function($v) {
				if ($v === null || $v == '') {
					return false;
				}
				return true;
			});
			break;
		}
	}

	if ($filedata['domain'] == 'youtube' && array_key_exists('AlbumArtist', $data)) {
		// Workaround a mopidy-youtube bug where sometimes it reports incorrect Artist info
		// if the item being added to the queue is not the result of a search. In this case we will
		// (almost) always have AlbumArtist info, so use that and it'll then stay consistent with the collection
		$data['Artist'] = $data['AlbumArtist'];
	}

	return $data;
}

function get_imagesearch_info($key) {

	// Used by utils/getalbumcover.php to get album and artist names etc based on an Image Key

	$retval = array('artist' => null, 'album' => null, 'mbid' => null, 'albumpath' => null, 'albumuri' => null, 'trackuri' => null);
	$queries = array(
		"SELECT DISTINCT
			Artisttable.Artistname,
			Albumname,
			mbid,
			Albumindex,
			AlbumUri,
			isSearchResult,
			Uri
		FROM
			Albumtable
			JOIN Artisttable ON AlbumArtistindex = Artisttable.Artistindex
			JOIN Tracktable USING (Albumindex)
			WHERE ImgKey = ? AND isSearchResult < 2 AND Hidden = 0",

		"SELECT DISTINCT
			Artisttable.Artistname,
			Albumname,
			mbid,
			Albumindex,
			AlbumUri,
			isSearchResult,
			Uri
		FROM
			Albumtable
			JOIN Artisttable ON AlbumArtistindex = Artisttable.Artistindex
			JOIN Tracktable USING (Albumindex)
			WHERE ImgKey = ? AND isSearchResult > 1"
	);

	foreach ($queries as $query) {
		$result = sql_prepare_query(false, PDO::FETCH_OBJ, null, null, $query, $key);

		// This can come back with multiple results if we have the same album on multiple backends
		// So we make sure we combine the data to get the best possible set
		foreach ($result as $obj) {
			if ($retval['artist'] == null) {
				$retval['artist'] = $obj->Artistname;
			}
			if ($retval['album'] == null) {
				$retval['album'] = $obj->Albumname;
			}
			if ($retval['mbid'] == null || $retval['mbid'] == "") {
				$retval['mbid'] = $obj->mbid;
			}
			if ($retval['albumpath'] == null) {
				$retval['albumpath'] = get_album_directory($obj->Albumindex, $obj->AlbumUri);
			}
			if ($retval['albumuri'] == null || $retval['albumuri'] == "") {
				$retval['albumuri'] = $obj->AlbumUri;
			}
			if ($retval['trackuri'] == null) {
				$retval['trackuri'] = $obj->Uri;
			}
			logger::log('BACKEND', "Found album",$retval['album'],",in database");
		}
	}
	return $retval;
}

function get_albumlink($albumindex) {
	return simple_query('AlbumUri', 'Albumtable', 'Albumindex', $albumindex, "");
}

function get_album_directory($albumindex, $uri) {
	global $prefs;
	$retval = null;
	// Get album directory by using the Uri of one of its tracks, making sure we choose only local tracks
	if (getDomain($uri) == 'local') {
		$result = generic_sql_query("SELECT Uri FROM Tracktable WHERE Albumindex = ".$albumindex." LIMIT 1");
		foreach ($result as $obj2) {
			$retval = dirname($obj2['Uri']);
			$retval = preg_replace('#^local:track:#', '', $retval);
			$retval = preg_replace('#^file://#', '', $retval);
			$retval = preg_replace('#^beetslocal:\d+:'.$prefs['music_directory_albumart'].'/#', '', $retval);
			logger::log('BACKEND', "Got album directory using track Uri :",$retval);
		}
	}
	return $retval;
}

function update_image_db($key, $found, $imagefile) {
	$val = ($found) ? $imagefile : null;
	if (sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET Image = ?, Searched = 1 WHERE ImgKey = ?", $val, $key)) {
		logger::log('BACKEND', "    Database Image URL Updated");
	} else {
		logger::warn('BACKEND', "    Failed To Update Database Image URL",$val,$key);
	}
}

function set_image_for_album($albumindex, $image) {
	logger::log('MYSQL', 'Setting image for album',$albumindex,'to',$image);
	sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET Image = ?, Searched = 1 WHERE Albumindex = ?", $image, $albumindex);
}

function track_is_hidden($ttid) {
	$h = simple_query('Hidden', 'Tracktable', 'TTindex', $ttid, 0);
	return ($h != 0) ? true : false;
}

function track_is_searchresult($ttid) {
	// This is for detecting tracks that were added as part of a search, or un-hidden as part of a search
	$h = simple_query('isSearchResult', 'Tracktable', 'TTindex', $ttid, 0);
	return ($h > 1) ? true : false;
}

function track_is_wishlist($ttid) {
	$u = simple_query('Uri', 'Tracktable', 'TTindex', $ttid, '');
	if ($u === null) {
		logger::mark('BACKEND', "Track",$ttid,"is wishlist. Discarding");
		generic_sql_query("DELETE FROM Playcounttable WHERE TTindex=".$ttid, true);
		generic_sql_query("DELETE FROM Tracktable WHERE TTindex=".$ttid, true);
		return true;
	}
	return false;
}

function check_for_wishlist_track(&$data) {
	$result = sql_prepare_query(false, PDO::FETCH_ASSOC, null, null, "SELECT TTindex FROM Tracktable JOIN Artisttable USING (Artistindex)
									WHERE Artistname=? AND Title=? AND Uri IS NULL",$data['artist'],$data['title']);
	foreach ($result as $obj) {
		logger::mark('BACKEND', "Wishlist Track",$obj['TTindex'],"matches the one we're adding");
		$meta = get_all_data($obj['TTindex']);
		$data['attributes'] = array();
		$data['attributes'][] = array('attribute' => 'Rating', 'value' => $meta['Rating']);
		$data['attributes'][] = array('attribute' => 'Tags', 'value' => $meta['Tags']);
		generic_sql_query("DELETE FROM Tracktable WHERE TTindex=".$obj['TTindex'], true);
	}
}

function remove_album_from_database($albumid) {
	generic_sql_query("DELETE FROM Tracktable WHERE Albumindex = ".$albumid, true);
	generic_sql_query("DELETE FROM Albumtable WHERE Albumindex = ".$albumid, true);
}

function get_album_tracks_from_database($which, $cmd) {
	global $prefs;
	$retarr = array();
	$sorter = choose_sorter_by_key($which);
	$lister = new $sorter($which);
	$result = $lister->track_sort_query();
	$cmd = ($cmd === null) ? 'add' : $cmd;
	foreach($result as $a) {
		$retarr[] = $cmd.' "'.$a['uri'].'"';
	}
	return $retarr;
}

function get_artist_tracks_from_database($which, $cmd) {
	global $prefs;
	$retarr = array();
	logger::log('BACKEND', "Getting Tracks for Root Item",$prefs['sortcollectionby'],$which);
	$sorter = choose_sorter_by_key($which);
	$lister = new $sorter($which);
	foreach ($lister->albums_for_artist() as $a) {
		$retarr = array_merge($retarr, get_album_tracks_from_database($a, $cmd));
	}
	return $retarr;
}

function get_highest_disc($tracks) {
	$n = 1;
	foreach ($tracks as $t) {
		if ($t['disc'] > $n) {
			$n = $t['disc'];
		}
	}
	return $n;
}

function get_album_details($albumindex) {
	return generic_sql_query(
		"SELECT Albumname, Artistname, Image, AlbumUri
		FROM Albumtable
		JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex
		WHERE Albumindex = ".$albumindex );
}

function get_artist_charts() {
	$artists = array();
	$query = "SELECT SUM(Playcount) AS playtot, Artistindex, Artistname FROM
		 Playcounttable JOIN Tracktable USING (TTindex) JOIN Artisttable USING (Artistindex)";
	$query .= " GROUP BY Artistindex ORDER BY playtot DESC LIMIT 40";
	$result = generic_sql_query($query, false, PDO::FETCH_OBJ);
	foreach ($result as $obj) {
		$artists[] = array( 'label_artist' => $obj->Artistname, 'soundcloud_plays' => $obj->playtot);
	}
	return $artists;
}

function get_album_charts() {
	$albums = array();
	$query = "SELECT SUM(Playcount) AS playtot, Albumname, Artistname, AlbumUri, Albumindex
		 FROM Playcounttable JOIN Tracktable USING (TTindex) JOIN Albumtable USING (Albumindex)
		 JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex";
	$query .= " GROUP BY Albumindex ORDER BY playtot DESC LIMIT 40";
	$result = generic_sql_query($query, false, PDO::FETCH_OBJ);
	foreach ($result as $obj) {
		$albums[] = array( 'label_artist' => $obj->Artistname,
			'label_album' => $obj->Albumname,
			'soundcloud_plays' => $obj->playtot, 'uri' => $obj->AlbumUri);
	}
	return $albums;
}

function get_track_charts($limit = 40) {
	$tracks = array();
	// Group by title and sum because we may have the same track on multiple backends
	$query = "SELECT
				Title,
				SUM(Playcount) AS Playcount,
				Artistname,
				".SQL_URI_CONCAT." AS Uris
			FROM
				Tracktable
				JOIN Playcounttable USING (TTIndex)
				JOIN Artisttable USING (Artistindex)
			GROUP BY Title, Artistname
			ORDER BY Playcount DESC LIMIT ".$limit;
	$result = generic_sql_query($query, false, PDO::FETCH_OBJ);
	foreach ($result as $obj) {
		$uri = null;
		$uris = explode(',', $obj->Uris);
		foreach ($uris as $u) {
			if ($uri === null) {
				$uri = $u;
			} else if (getDomain($uri) != 'local' && getDomain($u) == 'local') {
				// Prepfer local to internet
				$uri = $u;
			} else if (getDomain($uri) == 'youtube' && strpos($u, 'prefs/youtubedl') !== false) {
				// Prefer downloaded youtube tracks to online ones
				$uri = $u;
			}
		}

		$tracks[] = array(
			'label_artist' => $obj->Artistname,
			'label_track' => $obj->Title,
			'soundcloud_plays' => $obj->Playcount,
			'uri' => $uri);
	}
	return $tracks;
}

function find_justadded_artists() {
	return sql_get_column("SELECT DISTINCT AlbumArtistindex FROM Albumtable JOIN Tracktable USING (Albumindex) WHERE justAdded = 1", 0);
}

function find_justadded_albums() {
	return sql_get_column("SELECT DISTINCT Albumindex FROM Tracktable WHERE justAdded = 1", 0);
}

function get_user_radio_streams() {
	return generic_sql_query("SELECT * FROM RadioStationtable WHERE IsFave = 1 ORDER BY Number, StationName");
}

function remove_user_radio_stream($x) {
	generic_sql_query("UPDATE RadioStationtable SET IsFave = 0, Number = 65535 WHERE Stationindex = ".$x, true);
}

function save_radio_order($order) {
	foreach ($order as $i => $o) {
		logger::trace('RADIO ORDER', 'Station',$o,'index',$i);
		generic_sql_query("UPDATE RadioStationtable SET Number = ".$i." WHERE Stationindex = ".$o, true);
	}
}

function check_radio_station($playlisturl, $stationname, $image) {
	global $mysqlc;
	$index = null;
	$index = sql_prepare_query(false, null, 'Stationindex', false, "SELECT Stationindex FROM RadioStationtable WHERE PlaylistUrl = ?", $playlisturl);
	if ($index === false) {
		logger::mark('BACKEND', "Adding New Radio Station");
		logger::log('BACKEND', "  Name  :",$stationname);
		logger::log('BACKEND', "  Image :",$image);
		logger::log('BACKEND', "  URL   :",$playlisturl);
		if (sql_prepare_query(true, null, null, null, "INSERT INTO RadioStationtable (IsFave, StationName, PlaylistUrl, Image) VALUES (?, ?, ?, ?)",
								0, trim($stationname), trim($playlisturl), trim($image))) {
			$index = $mysqlc->lastInsertId();
			logger::log('BACKEND', "Created new radio station with index ".$index);
		}
	} else {
		sql_prepare_query(true, null, null, null, "UPDATE RadioStationtable SET StationName = ?, Image = ? WHERE Stationindex = ?",
			trim($stationname), trim($image), $index);
		logger::mark('BACKEND', "Found radio station",$stationname,"with index",$index);
	}
	return $index;
}

function check_radio_tracks($stationid, $tracks) {
	generic_sql_query("DELETE FROM RadioTracktable WHERE Stationindex = ".$stationid, true);
	foreach ($tracks as $track) {
		$index = sql_prepare_query(false, null, 'Stationindex', false, "SELECT Stationindex FROM RadioTracktable WHERE TrackUri = ?", trim($track['TrackUri']));
		if ($index !== false) {
			logger::log('BACKEND', "  Track already exists for stationindex",$index);
			$stationid = $index;
		} else {
			logger::log('BACKEND', "  Adding New Track",$track['TrackUri'],"to station",$stationid);
			sql_prepare_query(true, null, null, null, "INSERT INTO RadioTracktable (Stationindex, TrackUri, PrettyStream) VALUES (?, ?, ?)",
								$stationid, trim($track['TrackUri']), trim($track['PrettyStream']));
		}
	}
	return $stationid;
}

function add_fave_station($info) {
	if (array_key_exists('streamid', $info) && $info['streamid']) {
		logger::mark('BACKEND', "Updating StationIndex",$info['streamid'],"to be fave");
		generic_sql_query("UPDATE RadioStationtable SET IsFave = 1 WHERE Stationindex = ".$info['streamid'], true);
		return true;
	}
	$stationindex = check_radio_station($info['location'],$info['album'],$info['image']);
	$stationindex = check_radio_tracks($stationindex, array(array('TrackUri' => $info['location'], 'PrettyStream' => $info['stream'])));
	generic_sql_query("UPDATE RadioStationtable SET IsFave = 1 WHERE Stationindex = ".$stationindex, true);
}

function update_radio_station_name($info) {
	if ($info['streamid']) {
		logger::mark('BACKEND', "Updating Stationindex",$info['streamid'],"with new name",$info['name']);
		sql_prepare_query(true, null, null, null, "UPDATE RadioStationtable SET StationName = ? WHERE Stationindex = ?",$info['name'],$info['streamid']);
	} else {
		$stationid = check_radio_station($info['uri'], $info['name'], '');
		check_radio_tracks($stationid, array(array('TrackUri' => $info['uri'], 'PrettyStream' => '')));
	}
}

function find_stream_name_from_index($index) {
	return simple_query('StationName', 'RadioStationtable', 'StationIndex', $index, '');
}

function update_stream_image($stream, $image) {
	sql_prepare_query(true, null, null, null, "UPDATE RadioStationtable SET Image = ? WHERE StationName = ?",$image,$stream);
}

function update_podcast_image($podid, $image) {
	logger::log('BACKEND', "Setting Image to",$image,"for podid",$podid);
	sql_prepare_query(true, null, null, null, 'UPDATE Podcasttable SET Image = ? WHERE PODindex = ?',$image, $podid);
}

function find_radio_track_from_url($url) {
	return sql_prepare_query(false, PDO::FETCH_OBJ, null, null,
								"SELECT
									Stationindex, PlaylistUrl, StationName, Image, PrettyStream
									FROM
									RadioStationtable JOIN RadioTracktable USING (Stationindex)
									WHERE TrackUri = ?",$url);
}

//
// Database Global Stats and Version Control
//

function update_track_stats() {
	logger::mark('BACKEND', "Updating Track Stats");
	$t = microtime(true);
	update_stat('ArtistCount',get_artist_count(ADDED_ALL_TIME, 0));
	update_stat('AlbumCount',get_album_count(ADDED_ALL_TIME, 0));
	update_stat('TrackCount',get_track_count(ADDED_ALL_TIME, 0));
	update_stat('TotalTime',get_duration_count(ADDED_ALL_TIME, 0));
	update_stat('BookArtists',get_artist_count(ADDED_ALL_TIME, 1));
	update_stat('BookAlbums',get_album_count(ADDED_ALL_TIME, 1));
	update_stat('BookTracks',get_track_count(ADDED_ALL_TIME, 1));
	update_stat('BookTime',get_duration_count(ADDED_ALL_TIME, 1));
	$at = microtime(true) - $t;
	logger::info('BACKEND', "Updating Track Stats took ".$at." seconds");
}

function update_stat($item, $value) {
	generic_sql_query("UPDATE Statstable SET Value='".$value."' WHERE Item='".$item."'", true);
}

function get_stat($item) {
	return simple_query('Value', 'Statstable', 'Item', $item, 0);
}

function get_artist_count($range, $iab) {
	$qstring = "SELECT COUNT(*) AS NumArtists FROM (SELECT AlbumArtistindex FROM Albumtable
		INNER JOIN Tracktable USING (Albumindex) WHERE Uri IS NOT NULL
		AND Hidden = 0 AND isSearchResult < 2 AND isAudiobook";
	$qstring .= ($iab == 0) ? ' = ' : ' > ';
	$qstring .= '0 '.track_date_check($range, 'a')." GROUP BY AlbumArtistindex) AS t";
	return generic_sql_query($qstring, false, null, 'NumArtists', 0);
}

function get_album_count($range, $iab) {
	$qstring = "SELECT COUNT(*) AS NumAlbums FROM (SELECT Albumindex FROM Tracktable WHERE Uri IS NOT NULL
		AND Hidden = 0 AND isSearchResult < 2 AND isAudiobook";
	$qstring .= ($iab == 0) ? ' = ' : ' > ';
	$qstring .= '0 '.track_date_check($range, 'a')." GROUP BY Albumindex) AS t";
	return generic_sql_query($qstring, false, null, 'NumAlbums', 0);
}

function get_track_count($range, $iab) {
	$qstring = "SELECT COUNT(*) AS NumTracks FROM Tracktable WHERE Uri IS NOT NULL AND Hidden=0 AND isAudiobook";
	$qstring .= ($iab == 0) ? ' = ' : ' > ';
	$qstring .= '0 '.track_date_check($range, 'a')." AND isSearchResult < 2";
	return generic_sql_query($qstring, false, null, 'NumTracks', 0);
}

function get_duration_count($range, $iab) {
	$qstring = "SELECT SUM(Duration) AS TotalTime FROM Tracktable WHERE Uri IS NOT NULL AND Hidden=0 AND isAudiobook";
	$qstring .= ($iab == 0) ? ' = ' : ' > ';
	$qstring .= '0 '.track_date_check($range, 'a')." AND isSearchResult < 2";
	$ac = generic_sql_query($qstring, false, null, 'TotalTime', 0);
	if ($ac == '') {
		$ac = 0;
	}
	return $ac;
}

function dumpAlbums($which) {
	global $divtype, $prefs;
	$sorter = choose_sorter_by_key($which);
	$lister = new $sorter($which);
	$lister->output_html();
}

function collectionStats() {
	global $prefs;
	$html = '<div id="fothergill" class="brick brick_wide">';
	if ($prefs['collectionrange'] == ADDED_ALL_TIME) {
		$html .= alistheader(get_stat('ArtistCount'),
							get_stat('AlbumCount'),
							get_stat('TrackCount'),
							format_time(get_stat('TotalTime'))
						);
	} else {
		$html .= alistheader(get_artist_count($prefs['collectionrange'], 0),
							get_album_count($prefs['collectionrange'], 0),
							get_track_count($prefs['collectionrange'], 0),
							format_time(get_duration_count($prefs['collectionrange'], 0)));
	}
	$html .= '</div>';
	return $html;
}

function audiobookStats() {
	global $prefs;
	$html = '<div id="mingus" class="brick brick_wide">';

	if ($prefs['collectionrange'] == ADDED_ALL_TIME) {
		$html .= alistheader(get_stat('BookArtists'),
							get_stat('BookAlbums'),
							get_stat('BookTracks'),
							format_time(get_stat('BookTime'))
						);
	} else {
		$html .= alistheader(get_artist_count($prefs['collectionrange'], 1),
							get_album_count($prefs['collectionrange'], 1),
							get_track_count($prefs['collectionrange'], 1),
							format_time(get_duration_count($prefs['collectionrange'], 1)));
	}
	$html .= "</div>";
	return $html;
}

function searchStats() {
	$numartists = generic_sql_query(
		"SELECT COUNT(*) AS NumArtists FROM (SELECT DISTINCT AlbumArtistIndex FROM Albumtable
		INNER JOIN Tracktable USING (Albumindex) WHERE Albumname IS NOT NULL AND Uri IS NOT
		NULL AND Hidden = 0 AND isSearchResult > 0) AS t", false, null, 'NumArtists', 0);

	$numalbums = generic_sql_query(
		"SELECT COUNT(*) AS NumAlbums FROM (SELECT DISTINCT Albumindex FROM Albumtable
		INNER JOIN Tracktable USING (Albumindex) WHERE Albumname IS NOT NULL AND Uri IS NOT
		NULL AND Hidden = 0 AND isSearchResult > 0) AS t", false, null, 'NumAlbums', 0);

	$numtracks = generic_sql_query(
		"SELECT COUNT(*) AS NumTracks FROM Tracktable WHERE Uri IS NOT NULL
		AND Hidden=0 AND isSearchResult > 0", false, null, 'NumTracks', 0);

	$numtime = generic_sql_query(
		"SELECT SUM(Duration) AS TotalTime FROM Tracktable WHERE Uri IS NOT NULL AND
		Hidden=0 AND isSearchResult > 0", false, null, 'TotalTime', 0);

	$html =  '<div id="searchstats" class="brick brick_wide">';
	$html .= alistheader($numartists, $numalbums, $numtracks, format_time($numtime));
	$html .= '</div>';
	return $html;

}

function getItemsToAdd($which, $cmd = null) {
	$a = preg_match('/^(a|b|r|t|y|u|z)(.*?)(\d+|root)/', $which, $matches);
	if (!$a) {
		logger::error('BACKEND', "Regexp failed to match",$which);
		return array();
	}
	$what = $matches[2];
	logger::log('BACKEND','Getting tracks for',$which,$cmd);
	switch ($what) {
		case "album":
			return get_album_tracks_from_database($which, $cmd);
			break;

		default:
			return get_artist_tracks_from_database($which, $cmd);
			break;

	}
}

function alistheader($nart, $nalb, $ntra, $tim) {
	return '<div style="margin-bottom:4px">'.
	'<table width="100%" class="playlistitem">'.
	'<tr><td align="left">'.$nart.' '.language::gettext("label_artists").
	'</td><td align="right">'.$nalb.' '.language::gettext("label_albums").'</td></tr>'.
	'<tr><td align="left">'.$ntra.' '.language::gettext("label_tracks").
	'</td><td align="right">'.$tim.'</td></tr>'.
	'</table>'.
	'</div>';
}

function playAlbumFromTrack($uri) {
	// Used when CD player mode is on.
	global $prefs;
	$result = sql_prepare_query(false, PDO::FETCH_OBJ, null, null, "SELECT Albumindex, TrackNo, Disc, isSearchResult, isAudiobook FROM Tracktable WHERE Uri = ?", $uri);
	$album = array_shift($result);
	$retval = array();
	if ($album) {
		if ($album->isSearchResult) {
			$why = 'b';
		} else if ($album->isAudiobook) {
			$why = 'z';
		} else {
			$why = 'a';
		}
		$alltracks = get_album_tracks_from_database($why.'album'.$album->Albumindex, 'add');
		$count = 0;
		while (strpos($alltracks[$count], $uri) === false) {
			$count++;
		}
		$retval = array_slice($alltracks, $count);
	} else {
		// If we didn't find the track in the database, that'll be because it's
		// come from eg a spotifyAlbumThing or something like that (the JS doesn't discriminate)
		// so in this case just add the track
		$retval = array('add "'.$uri.'"');
	}
	return $retval;
}

function check_url_against_database($url, $itags, $rating) {
	global $mysqlc;
	if ($mysqlc === null) connect_to_database();
	$qstring = "SELECT COUNT(t.TTindex) AS num FROM Tracktable AS t ";
	$tags = array();
	if ($itags !== null) {
		$qstring .= "JOIN (SELECT DISTINCT TTindex FROM TagListtable JOIN Tagtable AS tag USING (Tagindex) WHERE";
		$tagterms = array();
		foreach ($itags as $tag) {
			$tags[] = trim($tag);
			$tagterms[] = " tag.Name LIKE ?";
		}
		$qstring .= implode(" OR",$tagterms);
		$qstring .=") AS j ON j.TTindex = t.TTindex ";
	}
	if ($rating !== null) {
		$qstring .= "JOIN (SELECT * FROM Ratingtable WHERE Rating >= ".$rating.") AS rat ON rat.TTindex = t.TTindex ";
	}
	$tags[] = $url;
	$qstring .= "WHERE t.Uri = ?";
	$count = sql_prepare_query(false, null, 'num', 0, $qstring, $tags);
	if ($count > 0) {
		return true;
	}
	return false;
}

function cleanSearchTables() {
	// Clean up the database tables before performing a new search or updating the collection

	logger::mark('BACKEND', "Cleaning Search Results");
	// Any track that was previously hidden needs to be re-hidden
	generic_sql_query("UPDATE Tracktable SET Hidden = 1, isSearchResult = 0 WHERE isSearchResult = 3", true);

	// Any track that was previously a '2' (added to database as search result) but now
	// has a playcount needs to become a zero and be hidden.
	hide_played_tracks();

	// remove any remaining '2's
	generic_sql_query("DELETE FROM Tracktable WHERE isSearchResult = 2", true);

	// Set '1's back to '0's
	generic_sql_query("UPDATE Tracktable SET isSearchResult = 0 WHERE isSearchResult = 1", true);

	// This may leave some orphaned albums and artists
	remove_cruft();

	//
	// remove_cruft creates some temporary tables and we need to remove them because
	// remove cruft will be called again later on if we're doing a collection update.
	// Sadly, DROP TABLE runs into locking problems, at least with SQLite, so instead
	// we close the DB connection and start again.
	// So this function must be called BEFORE prepareCollectionUpdate, as that creates
	// temporary tables of its own.
	//
	close_database();
	sleep(1);
	connect_to_database();

}

//
// Stuff to do with creating the database from a music collection (collection.php)
//

function collectionUpdateRunning() {
	$cur = simple_query('Value', 'Statstable', 'Item', 'Updating', null);
	switch ($cur) {
		case null:
			logger::warn('COLLECTION', 'Got null response to update lock check');
		case '0':
			generic_sql_query("UPDATE Statstable SET Value = 1 WHERE Item = 'Updating'", true);
			return false;

		case '1':
			logger::error('COLLECTION', 'Multiple collection updates attempted');
			return true;
	}
}

function clearUpdateLock() {
	logger::debug('COLLECTION', 'Clearing update lock');
	generic_sql_query("UPDATE Statstable SET Value = 0 WHERE Item = 'Updating'", true);
}

function prepareCollectionUpdate() {
	create_foundtracks();
	prepare_findtracks();
	open_transaction();
}

function prepare_findtracks() {
	global $find_track, $update_track;
	if ($find_track = sql_prepare_query_later(
		"SELECT TTindex, Disc, LastModified, Hidden, isSearchResult, Uri, isAudiobook, Genreindex, TYear FROM Tracktable WHERE Title=? AND ((Albumindex=? AND TrackNo=? AND Disc=?) OR (Artistindex=? AND Uri IS NULL))")) {
	} else {
		show_sql_error();
		exit(1);
	}

	if ($update_track = sql_prepare_query_later(
		"UPDATE Tracktable SET LinkChecked=0, Trackno=?, Duration=?, Disc=?, LastModified=?, Uri=?, Albumindex=?, isSearchResult=?, isAudiobook=?, Hidden=0, justAdded=1, Genreindex=?, TYear = ? WHERE TTindex=?")) {
	} else {
		show_sql_error();
		exit(1);
	}

}

function remove_findtracks() {
	global $find_track, $update_track;
	$find_track = null;
	$update_track = null;
}

function tidy_database() {
	// Find tracks that have been removed
	logger::mark('BACKEND', "Starting Cruft Removal");
	$now = time();
	logger::trace('BACKEND', "Finding tracks that have been deleted");
	generic_sql_query("DELETE FROM Tracktable WHERE LastModified IS NOT NULL AND Hidden = 0 AND justAdded = 0", true);
	remove_cruft();
	logger::log('COLLECTION', 'Updating collection version to', ROMPR_COLLECTION_VERSION);
	update_stat('ListVersion',ROMPR_COLLECTION_VERSION);
	update_track_stats();
	$dur = format_time(time() - $now);
	logger::info('BACKEND', "Cruft Removal Took ".$dur);
	logger::info('BACKEND', "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~");
	close_transaction();
}

function create_foundtracks() {
	// The order of these is VERY IMPORTANT!
	// Also the WHERE (thing) = 1 is important otherwise, at least with MySQL, it sets EVERY ROW to 0
	// whether or not it's already 0. That takes a very long time
	generic_sql_query("UPDATE Tracktable SET justAdded = 0 WHERE justAdded = 1", true);
	generic_sql_query("UPDATE Albumtable SET justUpdated = 0 WHERE justUpdated = 1", true);
}

function remove_cruft() {
	logger::log('BACKEND', "Removing orphaned albums");
	$t = microtime(true);
	generic_sql_query("DELETE FROM Albumtable WHERE Albumindex NOT IN (SELECT DISTINCT Albumindex FROM Tracktable)", true);
	$at = microtime(true) - $t;
	logger::info('BACKEND', " -- Removing orphaned albums took ".$at." seconds");

	logger::log('BACKEND', "Removing orphaned artists");
	$t = microtime(true);
	delete_orphaned_artists();
	$at = microtime(true) - $t;
	logger::info('BACKEND', " -- Removing orphaned artists took ".$at." seconds");

	logger::log('BACKEND', "Tidying Metadata");
	$t = microtime(true);
	generic_sql_query("DELETE FROM Ratingtable WHERE Rating = '0'", true);
	generic_sql_query("DELETE FROM Ratingtable WHERE TTindex NOT IN (SELECT TTindex FROM Tracktable WHERE Hidden = 0)", true);
	generic_sql_query("DELETE FROM TagListtable WHERE TTindex NOT IN (SELECT TTindex FROM Tracktable WHERE Hidden = 0)", true);
	generic_sql_query("DELETE FROM Genretable WHERE Genreindex NOT IN (SELECT DISTINCT Genreindex FROM Tracktable)", true);

	// Temporary table needed  because we can't use an IN clause as it conflicts with a trigger
	generic_sql_query("CREATE TEMPORARY TABLE used_tags AS SELECT DISTINCT Tagindex FROM TagListtable", true);
	generic_sql_query("DELETE FROM Tagtable WHERE Tagindex NOT IN (SELECT Tagindex FROM used_tags)", true);
	generic_sql_query("DELETE FROM Playcounttable WHERE Playcount = '0'", true);
	generic_sql_query("DELETE FROM Playcounttable WHERE TTindex NOT IN (SELECT TTindex FROM Tracktable)", true);

	$at = microtime(true) - $t;
	logger::info('BACKEND', " -- Tidying metadata took ".$at." seconds");
}

function do_track_by_track($trackobject) {

	// Tracks must have disc and albumartist tags to be handled by this method.
	// Loads of static variables to speed things up - we don't have to look things up every time.

	static $current_albumartist = null;
	static $current_album = null;
	static $current_domain = null;
	static $current_albumlink= null;
	static $albumobj = null;

	if ($trackobject === null) {
		if ($albumobj !== null) {
			$albumobj->check_database();
			$albumobj = null;
		}
		return true;
	}

	$artistname = $trackobject->get_sort_artist();

	if ($albumobj === null ||
		$current_albumartist != $artistname ||
		$current_album != $trackobject->tags['Album'] ||
		$current_domain != $trackobject->tags['domain'] ||
		($trackobject->tags['X-AlbumUri'] != null && $trackobject->tags['X-AlbumUri'] != $current_albumlink)) {
		if ($albumobj !== null) {
			$albumobj->check_database();
		}
		$albumobj = new album($trackobject);
	} else {
		$albumobj->newTrack($trackobject);
	}

	$current_albumartist = $artistname;
	$current_album = $albumobj->name;
	$current_domain = $albumobj->domain;
	$current_albumlink = $albumobj->uri;

}

function check_genre($genre) {
	global $mysqlc;
	$index = simple_query('Genreindex', 'Genretable', 'Genre', $genre, null);
	if ($index === null) {
		sql_prepare_query(true, null, null, null, 'INSERT INTO Genretable (Genre) VALUES(?)', $genre);
		$index = $mysqlc->lastInsertId();
		logger::log('BACKEND', 'Created Genre '.$genre);
	}
	return $index;
}

function check_and_update_track($trackobj, $albumindex, $artistindex, $artistname) {
	global $find_track, $update_track, $numdone, $prefs, $doing_search;
	static $current_trackartist = null;
	static $trackartistindex = null;
	static $current_genre = null;
	static $genreindex = null;

	if ($trackobj->tags['Genre'] != $current_genre || $genreindex === null) {
		$current_genre = $trackobj->tags['Genre'];
		$genreindex = check_genre($trackobj->tags['Genre']);
	}

	$dbtrack = (object) [
		'TTindex' => null,
		'LastModified' => null,
		'Hidden' => 0,
		'Disc' => 0,
		'Uri' => null,
		'isSearchResult' => 0,
		'isAudiobook' => 0,
		'Genreindex' => null,
		'TYear' => null
	];

	// Why are we not checking by URI? That should be unique, right?
	// Well, er. no. They're not.
	// Especially Spotify returns the same URI multiple times if it's in mutliple playlists
	// We CANNOT HANDLE that. Nor do we want to.

	// The other advantage of this is that we can put an INDEX on Albumindex, TrackNo, and Title,
	// which we can't do with Uri cos it's too long - this speeds the whole process up by a factor
	// of about 32 (9 minutes when checking by URI vs 15 seconds this way, on my collection)
	// Also, URIs might change if the user moves his music collection.

	if ($prefs['collection_type'] == "sqlite") {
		// Lord knows why, but we have to re-prepare these every single bloody time!
		prepare_findtracks();
	}

	if ($find_track->execute(array(
		$trackobj->tags['Title'],
		$albumindex,
		$trackobj->tags['Track'],
		$trackobj->tags['Disc'],
		$artistindex))) {
		while ($t = $find_track->fetch(PDO::FETCH_OBJ)) {
			$dbtrack = $t;
			break;
		}
	} else {
		show_sql_error();
		return false;
	}

	// NOTE: It is imperative that the search results have been tidied up -
	// i.e. there are no 1s or 2s in the database before we do a collection update

	// When doing a search, we MUST NOT change lastmodified of any track, because this will cause
	// user-added tracks to get a lastmodified date, and lastmodified == NULL
	// is how we detect user-added tracks and prevent them being deleted on collection updates

	// Note the use of === to detect LastModified, because == doesn't tell the difference between 0 and null
	//  - so if we have a manually added track and then add a collection track over it from a backend that doesn't
	//  give us LastModified (eg Spotify-Web), we don't update lastModified and the track remains manually added.

	// isaudiobook is 2 for anything manually moved to Spoken Word - we don't want these being reset

	if ($dbtrack->TTindex) {
		if ((!$doing_search && $trackobj->tags['Last-Modified'] !== $dbtrack->LastModified) ||
			($doing_search && $dbtrack->isSearchResult == 0) ||
			($trackobj->tags['Disc'] != $dbtrack->Disc && $trackobj->tags['Disc'] !== '') ||
			$dbtrack->Hidden != 0 ||
			$dbtrack->Genreindex != $genreindex ||
			($trackobj->tags['type'] == 'audiobook' && $dbtrack->isAudiobook == 0) ||
			($trackobj->tags['type'] != 'audiobook' && $dbtrack->isAudiobook == 1) ||
			$trackobj->tags['year'] != $dbtrack->TYear ||
			$trackobj->tags['file'] != $dbtrack->Uri) {

			//
			// Lots of debug output. All skipped if debug level < 7, to save those few ms
			//

			if ($prefs['debug_enabled'] > 6) {
				logger::trace('BACKEND', "  Updating track with ttid",$dbtrack->TTindex,"because :");
				if (!$doing_search && $dbtrack->LastModified === null) 								logger::trace('BACKEND', "    LastModified is not set in the database");
				if (!$doing_search && $trackobj->tags['Last-Modified'] === null) 					logger::trace('BACKEND', "    TrackObj LastModified is NULL too!");
				if (!$doing_search && $dbtrack->LastModified !== $trackobj->tags['Last-Modified']) 	logger::trace('BACKEND', "    LastModified has changed: We have ".$dbtrack->LastModified." but track has ".$trackobj->tags['Last-Modified']);
				if ($dbtrack->Disc != $trackobj->tags['Disc']) 										logger::trace('BACKEND', "    Disc Number has changed: We have ".$dbtrack->Disc." but track has ".$trackobj->tags['Disc']);
				if ($dbtrack->Hidden != 0) 															logger::trace('BACKEND', "    It is hidden");
				if ($dbtrack->Genreindex != $genreindex)											logger::trace("BACKEND", "    Genreindex needs to be changed from ".$dbtrack->Genreindex.' to '.$genreindex);
				if ($trackobj->tags['year'] != $dbtrack->TYear)							 			logger::trace('BACKEND', "    Year needs updatinf from", $dbtrack->TYear,'to',$trackobj->tags['year']);
				if ($trackobj->tags['type'] == 'audiobook' && $dbtrack->isAudiobook == 0) 			logger::trace('BACKEND', "    It needs to be marked as an Auidiobook");
				if ($trackobj->tags['type'] != 'audiobook' && $dbtrack->isAudiobook == 1) 			logger::trace('BACKEND', "    It needs to be un-marked as an Audiobook");
				if ($trackobj->tags['file'] != $dbtrack->Uri) {
					logger::trace('BACKEND', "    Uri has changed from : ".$dbtrack->Uri);
					logger::trace('BACKEND', "                      to : ".$trackobj->tags['file']);
				}
			}

			//
			// End of debug output
			//

			$newsearchresult = 0;
			if ($doing_search) {
				// Sometimes spotify search returns the same track with multiple URIs. This means we update the track
				// when we get the second one and isSearchResult gets set to zero unless we do this.
				$newsearchresult = $dbtrack->isSearchResult;
			}
			$newlastmodified = $trackobj->tags['Last-Modified'];
			if ($dbtrack->isSearchResult == 0 && $doing_search) {
				$newsearchresult = ($dbtrack->Hidden != 0) ? 3 : 1;
				logger::log('BACKEND', "    It needs to be marked as a search result : Value ".$newsearchresult);
				$newlastmodified = $dbtrack->LastModified;
			}
			$newisaudiobook = ($dbtrack->isAudiobook == 2) ? 2 : (($trackobj->tags['type'] == 'audiobook') ? 1 : 0);
			if ($update_track->execute(array(
				$trackobj->tags['Track'],
				$trackobj->tags['Time'],
				$trackobj->tags['Disc'],
				$newlastmodified,
				$trackobj->tags['file'],
				$albumindex,
				$newsearchresult,
				$newisaudiobook,
				$genreindex,
				$trackobj->tags['year'],
				$dbtrack->TTindex
			))) {
				$numdone++;
			} else {
				show_sql_error();
			}
		} else {
			generic_sql_query("UPDATE Tracktable SET justAdded = 1 WHERE TTindex = ".$dbtrack->TTindex, true);
		}
	} else {
		$a = $trackobj->get_artist_string();
		if ($a != $current_trackartist || $trackartistindex == null) {
			if ($artistname != $a && $a != null) {
				$trackartistindex = check_artist($a);
			} else {
				$trackartistindex = $artistindex;
			}
		}
		if ($trackartistindex == null) {
			logger::error('BACKEND', "ERROR! Trackartistindex is still null!");
			return false;
		}

		$current_trackartist = $a;
		$sflag = ($doing_search) ? 2 : 0;
		// 'Only variables can be passed by reference' hence create a variable
		$params = array(
			'title' => $trackobj->tags['Title'],
			'artist' => null,
			'trackno' => $trackobj->tags['Track'],
			'duration' => $trackobj->tags['Time'],
			'albumartist' => null,
			'albumuri' => null,
			'image' => null,
			'album' => null,
			'date' => null,
			'year' => $trackobj->tags['year'],
			'uri' => $trackobj->tags['file'],
			'trackai' => $trackartistindex,
			'albumai' => $artistindex,
			'albumindex' => $albumindex,
			'searched' => null,
			'imagekey' => null,
			'lastmodified' => $trackobj->tags['Last-Modified'],
			'disc' => $trackobj->tags['Disc'],
			'ambid' => null,
			'domain' => null,
			'hidden' => 0,
			'searchflag' => $sflag,
			'isaudiobook' => $trackobj->tags['type'] == 'audiobook' ? 1 : 0,
			'genreindex' => $genreindex
		);
		$ttid = create_new_track($params);
		$numdone++;
	}

	check_transaction();
}

?>
