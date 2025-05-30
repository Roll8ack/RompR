<?php

require_once ('getid3/getid3.php');

// Extend playlistCollection because it makes romonitor much neater.

class metaDatabase extends playlistCollection {

	public $returninfo = [ 'dummy' => 'baby' ];

	public const NODATA = [
		'isSearchResult' => 4,
		'Rating' => 0,
		'Playcount' => 0,
		'Tags' => []
	];

	public function sanitise_data(&$data) {

		//
		// Make sure the data we're dealing with is ROMPR_FILE_MODEL and do some sanity
		// checks on it to make certain important stuff isn't missing
		//

		$data = array_replace(MPD_FILE_MODEL, ROMPR_FILE_MODEL, $data);
		if ($data['albumartist'] === null) {
			logger::core('METADATA', 'albumartist is not set!');
			$data['albumartist'] = $data['trackartist'];
		}
		if ($data['Disc'] === null) {
			logger::core('METADATA', 'Disc is not set!');
			$data['Disc'] = 1;
		}
		if ($data['Genre'] === null) {
			logger::core('METADATA', 'Genre is not set!');
			$data['Genre'] = 'None';
		}
		if ($data['X-AlbumImage'] && substr($data['X-AlbumImage'],0,4) == "http") {
			logger::warn('METADATA', 'WARNING : Uncached remote image!');
			$data['X-AlbumImage'] = "getRemoteImage.php?url=".rawurlencode($data['X-AlbumImage']);
		}
		if ($data['ImgKey'] === null) {
			$albumimage = new baseAlbumImage(array(
				'artist' => imageFunctions::artist_for_image($data['type'], $data['albumartist']),
				'album' => $data['Album']
			));
			$data['ImgKey'] = $albumimage->get_image_key();
		}
		if ($data['year'] && !preg_match('/^\d\d\d\d$/', $data['year'])) {
			// If this has come from something like an 'Add Spotify Album To Collection' the year tag won't
			// exist but the Date tag might.
			logger::core('METADATA', 'Year is not a 4 digit year, analyzing Date field instead');
			$data['year'] = getYear($data['Date']);
		}

		if (($data['Track'] == 0 || $data['Track'] == '') &&
			$data['X-AlbumUri'] &&
			(strpos($data['X-AlbumUri'], 'yt:playlist:') !== false
			|| strpos($data['X-AlbumUri'], 'youtube:playlist:') !== false
			|| strpos($data['X-AlbumUri'], 'ytmusic:album:') !== false)
		) {
			logger::log('METADATA', 'Looking up Youtube Album to get Track Numnber');
			$player = new player();
			$dirs = [];
			foreach ($player->parse_list_output('find file "'.$data['X-AlbumUri'].'"', $dirs, false) as $filedata) {
				if ($filedata['Title'] == $data['Title']) {
					logger::trace('METADATA', 'Setting Track Number to', $filedata['Track']);
					$data['Track'] = $filedata['Track'];
				}
			}
			$player->close_mpd_connection();
		}
		// Very Important. The default in MPD_FILE_MODEL is 0 because that works for collection building
		$data['Last-Modified'] = null;
	}

	//
	// $data['albumuri'] should be a Uri that can be looked up using "find file"
	// We pass it through the
	//
	public function addalbumtocollection($data) {
		logger::info('METADATA', 'Adding album',$data['albumuri'],'to collection');
		$this->options['doing_search'] = true;
		$this->options['trackbytrack'] = false;
		$player = new player();
		$dirs = [];
		foreach ($player->parse_list_output('find file "'.$data['albumuri'].'"', $dirs, false) as $filedata) {
			$filedata['X-AlbumUri'] = $data['albumuri'];
			$this->newTrack($filedata);
		}
		foreach ($this->albums as $album) {
			$album->sortTracks();
			foreach($album->tracks as $track) {
				$playlistinfo = $this->doNewPlaylistFile($track->tags);
				$playlistinfo['action'] = 'set';
				$playlistinfo['urionly'] = true;
				$this->sanitise_data($playlistinfo);
				$this->set($playlistinfo);
			}
		}
	}

	//
	// Used for searching for a track and adding it to the collection
	// $data should contain things needed for fave_finder() - eg trackartist, Title, Album
	// as well as originalaction - a metedatabase action, eg 'set'
	// and some metadatabase attributes.
	// Searches for thr track and the uses originalaction to do the metadatbase action
	// to add it to the collection. This causes the usual returninfo mechanism to be
	// triggered so the UI can be updated in one motion.
	//
	public function findandset($data) {
		$matches = $this->fave_finder(
			false,
			true,
			$data,
			true
		);
		$best_match = array_shift($matches);
		if ($best_match) {
			logger::info('FINDAANDSET', 'Found', print_r($best_match, true));
			$track = $this->doNewPlaylistFile($best_match);
			$this->sanitise_data($track);
		} else {
			logger::info('FINDAANDSET', 'Did Not Find',$data['Title']);
			$track = $data;
			$track['file'] = null;
		}
		$track['action'] = $data['originalaction'];
		$track['attributes'] = $data['attributes'];
		$this->{$track['action']}($track);
	}

	public function findandreturn($data) {
		$matches = $this->fave_finder(
			false,
			false,
			$data,
			true
		);
		$best_match = array_shift($matches);
		if (!$best_match) $best_match = [];
		prefs::$database->returninfo = $best_match;
	}

	public function findandreturnall($data) {
		$this->fave_finder(
			false,
			false,
			$data,
			false
		);
		$this->tracks_as_array(true);
	}

	public function browsetoll($uri) {
		logger::info('METADATA', 'Adding album',$uri,'to listen later');
		$this->options['doing_search'] = true;
		$this->options['trackbytrack'] = false;
		$player = new player();
		$dirs = [];
		foreach ($player->parse_list_output('find file "'.$uri.'"', $dirs, false) as $filedata) {
			$filedata['X-AlbumUri'] = $uri;
			$this->newTrack($filedata);
		}
		$result = $this->generic_sql_query("SELECT * FROM AlbumsToListenTotable");
		logger::log('METADATA', 'Created',(count($this->albums)),'albums');
		foreach ($this->albums as $album) {
			$album->sortTracks();
			$check_id = $album->get_dummy_id();
			foreach ($result as $r) {
				$d = json_decode($r['JsonData'], true);
				if ($d['id'] == $check_id) {
					logger::warn("LISTENLATER", "Trying to add duplicate album to Listen Later");
					return;
				}
			}
			$json = $album->dump_json(null);
			$this->sql_prepare_query(true, null, null, null, "INSERT INTO AlbumsToListenTotable (JsonData) VALUES (?)", $json);
		}
	}

	public function ban($data) {
		logger::info('METADATA', 'Banning',$data['trackartist'],$data['Title']);
		$this->add_ban_track($data['trackartist'], $data['Title']);
	}

	public function set($data) {

		//
		// Set Metadata either on a new or an existing track
		//

		//
		// There's a ot of mess in here regarding coping with multiple TTIDs.
		// This can happen because some backends don't return Track Numbers all the time, but we do use them because you
		// can have 3 tracks with the same title on the same album and them all be different, can't you Jimi Hendrix?
		// So we might have a Hidden track or wishlist track without a track number, which has a Playcount.
		// Then we might do a search and add another copy of that track that does have a track number.
		// If we than Play that search result, we want the Playcount from the Hidden track.
		// find_item() doesn't check track numbers for tracks which are search results because otherwise
		// when we play the search result that's the only one it'll return and we won't get the Playcount.
		// If we add that track to the Collection of course, we probably lose it don't we?
		//

		if ($data['trackartist'] === null || $data['Title'] === null ) {
			logger::error("SET", "Something is not set");
			http_response_code(400);
			print json_encode(['error' => 'Artist or Title not set']);
			exit(0);
		}

		$ttids = $this->find_item($data, $this->forced_uri_only($data['urionly'], $data['domain']));

		if ($data['attributes'] == null)
			$data['attributes'] = [];

		$wishlist_attributes = [];
		$no_good = [];
		foreach ($ttids as $ttid) {
			//
			// If we found a track, check to see if it's in the wishlist and remove it if it is because
			// no longer want it, but preserve its metadata.
			//
			$frogbar = $this->track_is_wishlist($ttid);
			if ($frogbar !== false) {
				$no_good[] = $ttid;
				$wishlist_attributes = $frogbar;
			}

		}
		$ttids = array_diff($ttids, $no_good);

		if (count($ttids) == 0 && $data['urionly'] && count($wishlist_attributes) == 0) {
			//
			// In the case where urionly is set, we won't have matched on a wishlist track so check for one of
			// those here now.
			//
			$frogbar = $this->check_for_wishlist_track($data);
			if ($frogbar !== false) {
				$wishlist_attributes = $frogbar;
			}
		}

		$hidden_attributes = [];
		$no_good = [];
		foreach ($ttids as $ttid) {
			// Hidden tracks can have URIs. This is a problem because we might have matched on one
			// that is from a backend we can no longer play (eg spotify). If we just go through and
			// unhide it, we won't update the URI. If we attempt to update the track's URI we might
			// still have an album with a 'wrong' domain, and then check_album will get very confused
			// in the future and all in all it's just a mess.
			// A hidden track can only have a Playcount. Let's copy it, then delete the hidden track.
			$frogbar = $this->check_for_hidden_track($ttid);
			if ($frogbar !== false) {
				$no_good[] = $ttid;
				$hidden_attributes = $frogbar;
			}
		}
		$ttids = array_diff($ttids, $no_good);

		// Merge the set of thinsg we now have - anything from the wishlist or hidden track (which now no longer exist)
		// and anything we've been asked to set, with the latter taking precedence.
		$to_set = [];
		$set = array_merge($wishlist_attributes, $hidden_attributes, $data['attributes']);
		foreach ($set as $at) {
			$to_set[$at['attribute']] = $at['value'];
		}
		$data['attributes'] = [];
		foreach ($to_set as $at => $v) {
			$data['attributes'][] = ['attribute' => $at, 'value' => $v];
		}

		// If we still don't have any attributes (we might have come in with none as we do if we're
		// just adding an album to the collection via find file) then finally see if the ttid being added
		// already exists and take its rating (so we don't alter it) or just set rating to 0 to unhide
		// a hidden track via the trigger. Rmember that trigger also sets the justUpdated flag
		// and sets isSearchResult to 1 (where necessary) so we always need to do something at this point.
		if (count($data['attributes']) == 0) {
			if (count($ttids) > 0) {
				$rat_test = $this->simple_query('Rating', 'Ratingtable', 'TTindex', $ttids[0], 0);
			} else {
				$rat_test = 0;
			}
			$data['attributes'] = [['attribute' => 'Rating', 'value'=> $rat_test]];
		}

		if (count($ttids) == 0) {
			$ttids[0] = $this->create_new_track($data);
			logger::log("SET", "Created New Track with TTindex ".$ttids[0]);
		}

		if (count($data['attributes']) == 0) {
			logger::error('SET', 'No attributes to set!');
			return;
		}

		if (count($ttids) > 0 && $ttids[0] !== null && $this->doTheSetting($ttids, $data['attributes'], $data['file'])) {
			logger::debug('SET', 'Set command success');
		} else {
			logger::warn("SET", "Set Command failed");
			$this->returninfo['error'] = 'TTindex not found';
			logger::log('SET', $this->returninfo['error']);
			http_response_code(417);
		}
	}

	// public function seturi($data) {
	// 	// ONLY for updating the URI of a track via eg unplayabletracks
	// 	$ttindex = $data['reqid'];
	// 	$uri = $data['file'];
	// 	logger::log('HACKETY', 'Updating URI of TTindex',$ttindex,'to',$uri);
	// 	prefs::$database->sql_prepare_query(true, null, null, null,
	// 		"UPDATE Tracktable SET Uri = ?, Hidden = 0, LinkChecked = 0 WHERE TTindex = ?",
	// 		$uri,
	// 		$ttindex
	// 	);
	// 	$albumindex = prefs::$database->simple_query('Albumindex', 'Tracktable', 'TTindex', $ttindex, null);
	// 	$domain = prefs::$database->simple_query('Domain', 'Albumtable', 'Albumindex', $albumindex, null);
	// 	if ($domain != getDomain($uri)) {
	// 		prefs::$database->sql_prepare_query(true, null, null, null,
	// 			"UPDATE Albumtable SET AlbumUri = ?, Domain = ? WHERE Albumindex = ?",
	// 			null,
	// 			'local',
	// 			$albumindex
	// 		);
	// 	}
	// }

	public function inc($data) {

		//
		// NOTE : 'inc' does not do what you might expect.
		// This is not an 'increment' function, it still does a SET but it will create a hidden track
		// if the track can't be found, compare to SET which creates a new unhidden track.
		//

		if ($data['trackartist'] === null || $data['Title'] === null ||	$data['attributes'] == null) {
			logger::error("INC", "Something is not set",$data);
			http_response_code(400);
			print json_encode(array('error' => 'Artist or Title or Attributes not set'));
			exit(0);
		}

		$ttids = $this->find_item($data, $this->forced_uri_only(false, $data['domain']));
		if (count($ttids) == 0) {
			logger::log("INC", "Doing an INCREMENT action - Found NOTHING so creating hidden track");
			$data['hidden'] = 1;
			$ttids[0] = $this->create_new_track($data);
		}

		$this->checkLastPlayed($data);

		foreach ($ttids as $ttid) {
			logger::trace("INC", "Doing an INCREMENT action - Found TTID ",$ttid);
			foreach ($data['attributes'] as $pair) {
				logger::log("INC", "(Increment) Setting",$pair["attribute"],"to",$pair["value"],"on",$ttid);
				$this->increment_value($ttid, $pair["attribute"], $pair["value"], $data['lastplayed']);
				$this->up_next_hack_for_audiobooks($ttid);
			}
			$this->returninfo['metadata'] = $this->get_all_data($ttid);
		}
		return $ttids;
	}

	private function up_next_hack_for_audiobooks($ttid) {
		logger::trace('METADATA', 'Doing Audiobook Up Next Hack for TTID',$ttid);
		$this->sql_prepare_query(true, null, null, null,
			"UPDATE Bookmarktable SET Bookmark = 0 WHERE TTindex = ? AND Name = ?",
			$ttid,
			'Resume'
		);
		$this->sql_prepare_query(true, null, null, null,
			"UPDATE Albumtable SET justUpdated = 1 WHERE Albumindex IN
			(SELECT Albumindex FROM Tracktable WHERE TTindex = ? AND isAudiobook = ?)",
			$ttid, 1
		);
	}

	private function checkLastPlayed(&$data) {
		//
		// Return a LastPlayed value suitable for inerting into the database
		// either from the data or using the current timestamp
		//
		if ($data['lastplayed'] !== null && is_numeric($data['lastplayed'])) {
			// Convert timestamp from LastFM into MySQL TIMESTAMP format
			$data['lastplayed'] = date('Y-m-d H:i:s', $data['lastplayed']);
		} else if ($data['lastplayed'] !== null && preg_match('/\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d/', $data['lastplayed'])) {
			// Already in datestamp format as it would be eg when restoring a backup
		} else {
			$data['lastplayed'] = date('Y-m-d H:i:s');
			logger::log('INC', 'Setting lastplayed to',$data['lastplayed']);
		}
	}

	public function syncinc($data) {

		//
		// This is for syncing Last.FM playcounts
		//

		$this->sanitise_data($data);

		$ttids = $this->find_item($data, false);
		if (count($ttids) == 0) {
			$ttids = $this->inc($data);
			$this->resetSyncCounts($ttids);
			return true;
		}

		$this->checkLastPlayed($data);
		foreach ($ttids as $ttid) {
			logger::log("SYNCINC", "Doing a SYNC action on TTID ".$ttid,'LastPlayed is',$data['lastplayed']);
			$rowcount = $this->generic_sql_query("UPDATE Playcounttable SET SyncCount = SyncCount - 1 WHERE TTindex = ".$ttid." AND SyncCount > 0",
				false, null, null, null, true);
			if ($rowcount > 0) {
				logger::trace("SYNCINC", "  Decremented sync counter for this track");
			} else {
				$clp = $this->simple_query('LastPlayed', 'Playcounttable', 'TTindex', $ttid, null);
				if ($clp === null) {
					logger::trace('SYNCINC', 'Track does not currently have a playcount');
					$metadata = $this->get_all_data($ttid);
					$this->increment_value($ttid, 'Playcount', 1, $data['lastplayed']);
				} else {
					logger::trace('SYNCINC', 'Incrementing Playcount for this track');
					$this->sql_prepare_query(true, null, null, null,
						"UPDATE Playcounttable SET Playcount = Playcount + 1 WHERE TTindex = ?",
						$ttid
					);
					if (strtotime($clp) < strtotime($data['lastplayed'])) {
						logger::trace('SYNCINC', 'Updating LastPlayed for this track');
						$this->sql_prepare_query(true, null, null, null,
							"UPDATE Playcounttable SET LastPlayed = ? WHERE TTindex = ?",
							$data['lastplayed'],
							$ttid
						);
					}
				}
				// At this point, SyncCount must have been zero but the update will have incremented it again,
				// because of the trigger. resetSyncCounts takes care of this;
				$this->resetSyncCounts(array($ttid));
			}
		}

		// Let's just see if it's a podcast track and mark it as listened.
		// This won't always work, as scrobbles are often not what's in the RSS feed, but we can but do our best

		$boobly = $this->sql_prepare_query(false, PDO::FETCH_ASSOC, null, array(),
			"SELECT PODTrackindex FROM PodcastTracktable JOIN Podcasttable USING (PODindex)
			WHERE (Podcasttable.Artist LIKE ? OR PodcastTracktable.Artist LIKE ?)
			AND Podcasttable.Title LIKE ?
			AND PodcastTracktable.Title LIKE ?",
			$data['trackartist'],
			$data['trackartist'],
			$data['Album'],
			$data['Title']
		);
		$podtrack = (count($boobly) == 0) ? null : $boobly[0]['PODTrackindex'];
		if ($podtrack !== null) {
			logger::trace('SYNCINC', 'This track matches a Podcast episode');
			$this->sql_prepare_query(true, null, null, null,
				"UPDATE PodcastTracktable SET Listened = ?, New = ? WHERE PODTrackindex = ?",
				1,
				0,
				$podtrack
			);
		}
	}

	public function resetSyncCounts($ttids) {
		foreach ($ttids as $ttid) {
			$this->sql_prepare_query(true, null, null, null,
				"UPDATE Playcounttable SET SyncCount = 0 WHERE TTindex = ?",
				$ttid
			);
		}
	}

	public function remove($data) {

		//
		// Remove a tag from a track
		//

		if ($data['trackartist'] === null || $data['Title'] === null) {
			http_response_code(400);
			print json_encode(array('error' => 'Artist or Title not set'));
			exit(0);
		}
		$ttids = $this->find_item($data, $this->forced_uri_only($data['urionly'], $data['domain']));
		if (count($ttids) > 0) {
			foreach ($ttids as $ttid) {
				$result = true;
				foreach ($data['attributes'] as $pair) {
					logger::log("REMOVE", "Removing",$pair);
					$r = $this->remove_tag($ttid, $pair["value"]);
					if ($r == false) {
						logger::warn("REMOVE", "FAILED Removing",$pair);
						$result = false;
					}
				}
				if ($result) {
					$this->returninfo['metadata'] = $this->get_all_data($ttid);
				} else {
					http_response_code(417);
					$this->returninfo['error'] = 'Removing attributes failed';
				}
			}
		} else {
			logger::warn("USERRATING", "TTID Not Found");
			http_response_code(417);
			$this->returninfo['error'] = 'TTindex not found';
		}
	}

	public function get($data, $item = false) {

		//
		// Get all matadata for a track
		//

		if ($data['trackartist'] === null || $data['Title'] === null) {
			http_response_code(400);
			print json_encode(array('error' => 'Artist or Title not set'));
			exit(0);
		}
		$ttids = $this->find_item($data, $this->forced_uri_only(false, $data['domain']));

		$tempinfo = self::NODATA;
		foreach ($ttids as $ttid) {
			$this->returninfo = $this->get_all_data($ttid);
			$tempinfo['Rating'] = max($tempinfo['Rating'], $this->returninfo['Rating']);
			$tempinfo['Playcount'] = max($tempinfo['Playcount'], $this->returninfo['Playcount']);
			$tempinfo['Tags'] = (count($tempinfo['Tags']) > count($this->returninfo['Tags'])) ? $tempinfo['Tags'] : $this->returninfo['Tags'];
		}

		$this->returninfo['Rating'] = $tempinfo['Rating'];
		$this->returninfo['Playcount'] = $tempinfo['Playcount'];
		$this->returninfo['Tags'] = $tempinfo['Tags'];

		if ($item !== false)
			return array_key_exists($item, $this->returninfo) ? $this->returninfo[$item] : 0;
	}

	public function setalbummbid($data) {
		$ttids = $this->find_item($data, $this->forced_uri_only(false, $data['domain']));
		if (count($ttids) > 0) {
			foreach ($ttids as $ttid) {
				logger::trace("BACKEND", "Updating album MBID ".$data['attributes']." from TTindex ".$ttid);
				$albumindex = $this->simple_query('Albumindex', 'Tracktable', 'TTindex', $ttid, null);
				logger::debug("BACKEND", "   .. album index is ".$albumindex);
				$this->sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET mbid = ? WHERE Albumindex = ? AND mbid IS NULL",$data['attributes'],$albumindex);
			}
		}
	}

	public function updateAudiobookState($data) {
		$ttids = $this->find_item($data, $this->forced_uri_only(false, $data['domain']));
		if (count($ttids) > 0) {
			foreach ($ttids as $ttid) {
				logger::log('SQL', 'Setting Audiobook state for TTIndex',$ttid,'to',$data['isaudiobook']);
				$this->sql_prepare_query(true, null, null, null, 'UPDATE Tracktable SET isAudiobook = ? WHERE TTindex = ?', $data['isaudiobook'], $ttid);
			}
		}
	}

	public function cleanup($data) {
		logger::info("CLEANUP", "Doing Database Cleanup And Stats Update");
		$this->remove_cruft();
		$this->generic_sql_query("DELETE FROM Bookmarktable WHERE Bookmark = 0", true);
		$this->update_track_stats();
		$this->doCollectionHeader();
	}

	public function amendalbum($data) {
		if ($data['album_index'] !== null && $this->amend_album($data['album_index'], $data['albumartist'], $data['year'])) {
		} else {
			http_response_code(400);
			$this->returninfo['error'] = 'That just did not work';
		}
	}

	public function deletealbum($data) {
		if ($data['album_index'] !== null && $this->delete_album($data['album_index'])) {
		} else {
			http_response_code(400);
			$this->returninfo['error'] = 'That just did not work';
		}
	}

	public function setasaudiobook($data) {
		if ($data['album_index'] !== null && $this->set_as_audiobook($data['album_index'], $data['value'])) {
		} else {
			http_response_code(400);
			$this->returninfo['error'] = 'That just did not work';
		}
	}

	public function usetrackimages($data) {
		if ($data['album_index'] !== null && $this->use_trackimages($data['album_index'], $data['value'])) {
		} else {
			http_response_code(400);
			$this->returninfo['error'] = 'That just did not work';
		}
	}

	public function delete($data) {
		$ttids = $this->find_item($data, true);
		if (count($ttids) == 0) {
			http_response_code(400);
			$this->returninfo['error'] = 'TTindex not found';
		} else {
			$this->delete_track(array_shift($ttids));
		}
	}

	public function deletewl($data) {
		$this->delete_track($data['wltrack']);
	}

	public function deleteid($data) {
		$this->delete_track($data['ttid']);
	}

	public function clearwishlist() {
		logger::info("MONKEYS", "Removing Wishlist Tracks");
		if ($this->clear_wishlist()) {
			logger::debug("MONKEYS", " ... Success!");
		} else {
			logger::warn("MONKEYS", "Failed removing wishlist tracks");
		}
	}

	// Private Functions

	private function geturisfordir($data) {
		$player = new player();
		$uris = $player->get_uris_for_directory($data['file']);
		$ttids = array();
		foreach ($uris as $uri) {
			$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null, "SELECT TTindex FROM Tracktable WHERE Uri = ?", $uri);
			$ttids = array_merge($ttids, $t);
		}
		return $ttids;
	}

	private function geturis($data) {
		$uris = $this->getItemsToAdd($data['file'], "");
		$ttids = array();
		foreach ($uris as $uri) {
			$uri = trim(substr($uri, strpos($uri, ' ')+1, strlen($uri)), '"');
			$r = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null, "SELECT TTindex FROM Tracktable WHERE Uri = ?", $uri);
			$ttids = array_merge($ttids, $t);
		}
		return $ttids;
	}

	private function print_debug_ttids($ttids) {
		if (count($ttids) > 0) {
			logger::info("METADATA", "Found TTindex",implode(',', $ttids));
		} else {
			logger::info("METADATA", "Did not find a match");
		}
	}

	private function find_item($data, $urionly) {

		// $this->find_item
		//		Looks for a track in the database based on uri, title, artist, album, and albumartist or
		//		combinations of those
		//		Returns: Array of TTindex

		// find_item is used to find tracks on which to update or display metadata.
		// It is NOT used when the collection is created

		// When Setting Metadata we do not use a URI because we might have mutliple versions of the
		// track in the database or someone might be rating a track from Spotify that they already have
		// in Local. So in this case we check using an increasingly wider check to find the track,
		// returning as soon as one of these produces matches.
		//		First by Title, TrackNo, AlbumArtist and Album
		//		Third by Track, Album Artist, and Album
		// 		Then by Track, Track Artist, and Album
		//		Then by Track, Artist, and Album NULL (meaning wishlist)
		// We return ALL tracks found, because you might have the same track on multiple backends,
		// and set metadata on them all.
		// This means that when getting metadata it doesn't matter which one we match on.
		// When we Get Metadata we do supply a URI BUT we don't use it if we have one, just because.
		// $urionly can be set to force looking up only by URI. This is used by when we need to import a
		// specific version of the track  - currently from either the Last.FM importer or when we add a
		// spotify album to the collection

		// If we don't supply an album to this function that's because we're listening to the radio.
		// In that case we look for a match where there is something in the album field and then for
		// where album is NULL

		// $start_time = time();
		logger::mark("FIND ITEM", "Looking for item ".$data['Title']);
		$ttids = array();

		if ($urionly && $data['file']) {
			logger::log("FIND ITEM", "Trying by URI Only", $data['file']);
			$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null, "SELECT TTindex FROM Tracktable WHERE Uri = ?", $data['file']);
			$ttids = array_merge($ttids, $t);
		}

		if ($data['trackartist'] == null || $data['Title'] == null || ($urionly && $data['file'])) {
			$this->print_debug_ttids($ttids);
			return $ttids;
		}

		if (count($ttids) == 0) {
			// In this one ignore search results - we might have a search result with a track number which will override
			// a hidden track without one, and we don't want to do that.
			if ($data['albumartist'] !== null && $data['Track'] != 0) {
				logger::log("FIND ITEM", "  Trying by albumartist",$data['albumartist'],"album",$data['Album'],"title",$data['Title'],"track number",$data['Track']);
				$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null,
					"SELECT
						TTindex
					FROM
						Tracktable JOIN Albumtable USING (Albumindex)
						JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex
					WHERE
						Title = ?
						AND Artistname = ?
						AND Albumname = ?
						AND TrackNo = ?
						AND isSearchResult < 2",
					$data['Title'], $data['albumartist'], $data['Album'], $data['Track']);
				$ttids = array_merge($ttids, $t);
			}

			if (count($ttids) == 0 && $data['albumartist'] !== null) {
				logger::log("FIND ITEM", "  Trying by albumartist",$data['albumartist'],"album",$data['Album'],"and title",$data['Title']);
				$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null,
					"SELECT
						TTindex
					FROM
						Tracktable JOIN Albumtable USING (Albumindex)
						JOIN Artisttable ON Albumtable.AlbumArtistindex = Artisttable.Artistindex
					WHERE
						Title = ?
						AND Artistname = ?
						AND Albumname = ?",
					$data['Title'], $data['albumartist'], $data['Album']);
				$ttids = array_merge($ttids, $t);
			}

			if (count($ttids) == 0 && ($data['albumartist'] == null || $data['albumartist'] == $data['trackartist'])) {
				logger::log("FIND ITEM", "  Trying by trackartist",$data['trackartist'],",album",$data['Album'],"and title",$data['Title']);
				$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null,
					"SELECT
						TTindex
					FROM
						Tracktable JOIN Artisttable USING (Artistindex)
						JOIN Albumtable USING (Albumindex)
					WHERE
						Title = ?
						AND Artistname = ?
						AND Albumname = ?", $data['Title'], $data['trackartist'], $data['Album']);
				$ttids = array_merge($ttids, $t);
			}

			if (count($ttids) == 0 && !$data['Album']) {
				logger::log("FIND ITEM", "  Trying by trackartist",$data['trackartist'],"and title",$data['Title']);
				$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null,
					"SELECT
						TTindex
					FROM
						Tracktable JOIN Artisttable USING (Artistindex)
					WHERE
						Title = ?
						AND Artistname = ?
						AND Uri IS NOT NULL",
					$data['Title'], $data['trackartist']);
				$ttids = array_merge($ttids, $t);
			}

			// Finally look for Uri NULL which will be a wishlist item added via a radio station
			if (count($ttids) == 0) {
				logger::log("FIND ITEM", "  Trying by (wishlist) artist",$data['trackartist'],"and title",$data['Title']);
				$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null,
					"SELECT
						TTindex
					FROM
						Tracktable JOIN Artisttable USING (Artistindex)
					WHERE
						Title = ?
						AND Artistname = ?
						AND Uri IS NULL",
					$data['Title'], $data['trackartist']);
				$ttids = array_merge($ttids, $t);
			}
		}

		if (count($ttids) == 0 && !$urionly && $data['file']) {
			// Just in case. Sometimes Spotify changes titles on us.
			logger::log("FIND ITEM", "  Trying by URI ".$data['file']);
			$t = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, null, "SELECT TTindex FROM Tracktable WHERE Uri = ?", $data['file']);
			$ttids = array_merge($ttids, $t);
		}

		$this->print_debug_ttids($ttids);
		return $ttids;
	}

	private function increment_value($ttid, $attribute, $value, $lp) {

		// Increment_value doesn't 'increment' as such - it's used for setting values on tracks without
		// unhiding them. It's used for Playcount, which was originally an 'increment' type function but
		// that changed because multiple rompr instances cause multiple increments

		$current = $this->simple_query($attribute, $attribute.'table', 'TTindex', $ttid, null);
		if ($current !== null && $current >= $value) {
			// Don't INC if it has already been INCed, because this changes the LastPlayed time. This happens if romonitor has already updated
			// it and then we return to a browser, say on a mobile device, and that updates it again. The nowplaying_hack function
			// still ensures that the album gets marked as modified so that the UI updates. The UI will always update playcount before
			// romonitor does if the UI is open.
			logger::log('INCREMENT', 'Not incrementing',$attribute,'for TTindex',$ttid,'because current value',$current,'is >= new value',$value);
			return true;
		}

		logger::log("INCREMENT", "Setting",$attribute,"to",$value,'and lastplayed to',$lp,"for TTID",$ttid);
		if ($this->sql_prepare_query(true, null, null, null, "REPLACE INTO ".$attribute."table (TTindex, ".$attribute.", LastPlayed) VALUES (?, ?, ?)", $ttid, $value, $lp)) {
			logger::debug("INCREMENT", " .. success");
			// if ($attribute == 'Playcount' && $this->simple_query('isAudiobook', 'Tracktable', 'TTindex', $ttid, 0) == 1) {
			// 	logger::log('INCREMENT', 'Resetting resume position for TTID',$ttid);
			// 	// Always do this even if there is no stored progress to reset - it triggers the Progress trigger which makes the UI update
			// 	// so that the Up Next marker moves
			// 	$this->sql_prepare_query(true, null, null, null, 'REPLACE INTO Bookmarktable (TTindex, Bookmark, Name) VALUES (? ,?, ?)', $ttid, 0, 'Resume');
			// }
		} else {
			logger::warn("INCREMENT", "FAILED Setting",$attribute,"to",$value,"for TTID",$ttid);
			return false;
		}
		return true;

	}

	private function set_attribute($ttid, $attribute, $value) {

		// set_attribute
		//		Sets an attribute (Rating, Bookmark etc) on a TTindex.
		//		For this to work, the table must have columns TTindex, $attribute and must be called [$attribute]table, eg $attribute = Rating on Ratingtable

		if (is_array($value)) {
			// If $value is an array, it's expected to be used in a table with columns TTindex, $attribute, Name (eg $attribute = Bookmark)
			// The array should contain entries [Value for $attribute, Value for Name]
			// It's a bit of a hack but it works so far
			logger::log("ATTRIBUTE", "Setting",$attribute,"to",$value[0],$value[1],"on",$ttid);
			array_unshift($value, $ttid);
			if ($this->sql_prepare_query(true, null, null, null, "REPLACE INTO ".$attribute."table (TTindex, ".$attribute.", Name) VALUES (?, ?, ?)", $value)) {
				logger::debug("ATTRIBUTE", "  .. success");
			} else {
				logger::warn("ATTRIBUTE", "FAILED Setting",$attribute,"to",print_r($value, true));
				return false;
			}
		} else {
			logger::log("ATTRIBUTE", "Setting",$attribute,"to",$value,"on",$ttid);
			if ($this->sql_prepare_query(true, null, null, null, "REPLACE INTO ".$attribute."table (TTindex, ".$attribute.") VALUES (?, ?)", $ttid, $value)) {
				logger::debug("ATTRIBUTE", "  .. success");
			} else {
				logger::warn("ATTRIBUTE", "FAILED Setting",$attribute,"to",$value,"on",$ttid);
				return false;
			}
		}
		return true;
	}

	private function doTheSetting($ttids, $attributes, $uri) {
		$result = true;
		if ($attributes !== null) {
			logger::debug("USERRATING", "Setting attributes");
			foreach($ttids as $ttid) {
				foreach ($attributes as $pair) {
					logger::log("USERRATING", "Setting",$pair["attribute"],"to",$pair['value'],"on TTindex",$ttid);
					switch ($pair['attribute']) {
						case 'Tags':
							$result = $this->addTags($ttid, $pair['value']);
							break;

						default:
							$result = $this->set_attribute($ttid, $pair["attribute"], $pair["value"]);
							break;
					}
					if (!$result) { break; }
				}
				$this->check_audiobook_status($ttid);
				if ($uri) {
					logger::info('METADATA', 'Creating Return Info after Setting');
					$this->returninfo['metadata'] = $this->get_all_data($ttid);
				}
			}
		}
		return $result;
	}

	private function check_audiobook_status($ttid) {
		$albumindex = $this->sql_prepare_query(false, null, 'Albumindex', null,
			"SELECT Albumindex FROM Tracktable WHERE TTindex = ?",
			$ttid
		);
		if ($albumindex !== null) {
			$sorter = choose_sorter_by_key('zalbum'.$albumindex);
			$lister = new $sorter('zalbum'.$albumindex);
			if ($lister->album_trackcount($albumindex) > 0) {
				logger::log('USERRATING', 'Album '.$albumindex.' is an audiobook, updating track audiobook state');
				$this->sql_prepare_query(true, null, null, null,
					"UPDATE Tracktable SET isAudiobook = 2 WHERE TTindex = ?",
					$ttid
				);
			}
		}
	}

	private function addTags($ttid, $tags) {

		// addTags
		//		Add a list of tags to a TTindex

		foreach ($tags as $tag) {
			$t = trim($tag);
			if ($t == '') continue;
			logger::log("ADD TAGS", "Adding Tag",$t,"to TTindex",$ttid);
			$tagindex = $this->sql_prepare_query(false, null, 'Tagindex', null, "SELECT Tagindex FROM Tagtable WHERE Name=?", $t);
			if ($tagindex == null) $tagindex = $this->create_new_tag($t);
			if ($tagindex == null) {
				logger::warn("ADD TAGS", "    Could not create tag",$t);
				return false;
			}

			// Use REPLACE INTO - it's a bit slower but INSERT INTO throws an exception if the
			// tag relation alrady exists, and that spemas the error logs when restoring backups
			if ($this->sql_prepare_query(true, null, null, null,
					"REPLACE INTO TagListtable (TTindex, Tagindex) VALUES (?, ?)",
						$ttid,
						$tagindex
					)
			) {
				logger::debug("ADD TAGS", "Success");
				if (in_array($t, prefs::get_pref('auto_audiobook'))) {
					logger::log('ADD TAGS', 'Setting TTindex',$ttid,'as audiobook due to tag',$t);
					$albumindex = $this->simple_query('Albumindex', 'Tracktable', 'TTindex', $ttid, null);
					$this->set_as_audiobook($albumindex, 2);
				}
			}
		}
		return true;
	}

	private function create_new_tag($tag) {

		// create_new_tags
		//		Creates a new entry in Tagtable
		//		Returns: Tagindex

		logger::log("CREATETAG", "Creating new tag",$tag);
		$tagindex = null;
		if ($this->sql_prepare_query(true, null, null, null, "INSERT INTO Tagtable (Name) VALUES (?)", $tag)) {
			$tagindex = $this->mysqlc->lastInsertId();
		}
		return $tagindex;
	}

	private function remove_tag($ttid, $tag) {

		// remove_tags
		//		Removes a tag relation from a TTindex

		logger::log("REMOVE TAG", "Removing Tag",$tag,"from TTindex",$ttid);
		$retval = false;
		if ($tagindex = $this->simple_query('Tagindex', 'Tagtable', 'Name', $tag, false)) {
			$retval = $this->sql_prepare_query(true, null, null, null,
				"DELETE FROM TagListtable WHERE TTindex = ? AND Tagindex = ?",
				$ttid,
				$tagindex
			);
		} else {
			logger::warn("REMOVE TAG", "  ..  Could not find tag",$tag);
		}
		return $retval;
	}

	private function delete_track($ttid) {
		if ($this->remove_ttid($ttid)) {
		} else {
			http_response_code(400);
		}
	}

	private function amend_album($albumindex, $newartist, $date) {
		logger::mark("AMEND ALBUM", "Updating Album index",$albumindex,"with new artist",$newartist,"and new date",$date);
		$artistindex = ($newartist == null) ? null : $this->check_artist($newartist);
		$result = $this->sql_prepare_query(false, PDO::FETCH_OBJ, null, null, "SELECT * FROM Albumtable WHERE Albumindex = ?", $albumindex);
		$obj = array_shift($result);
		if ($obj) {
			$params = array(
				'Album' => $obj->Albumname,
				'albumartist_index' => ($artistindex == null) ? $obj->AlbumArtistindex : $artistindex,
				'X-AlbumUri' => $obj->AlbumUri,
				'X-AlbumImage' => $obj->Image,
				'year' => ($date == null) ? $obj->Year : getYear($date),
				'Searched' => $obj->Searched,
				'ImgKey' => $obj->ImgKey,
				'MUSICBRAINZ_ALBUMID' => $obj->mbid,
				'domain' => $obj->Domain);
			$newalbumindex = $this->check_album($params);
			foreach ([$albumindex, $newalbumindex] as $i) {
				$this->sql_prepare_query(true, null, null, null,
					"UPDATE Albumtable SET justUpdated = 1 WHERE Albumindex = ?",
					$i
				);
			}
			if ($albumindex != $newalbumindex) {
				logger::log("AMEND ALBUM", "Moving all tracks from album",$albumindex,"to album",$newalbumindex);
				if ($this->sql_prepare_query(true, null, null, null, "UPDATE Tracktable SET Albumindex = ? WHERE Albumindex = ?", $newalbumindex, $albumindex)) {
					logger::debug("AMEND ALBUM", "...Success");
				} else {
					logger::warn("AMEND ALBUM", "Track move Failed!");
					return false;
				}
			}
		} else {
			logger::error("AMEND ALBUM", "Failed to find album to update!");
			return false;
		}
		return true;
	}

	private function delete_album($albumindex) {
		$this->sql_prepare_query(true, null, null, null,
			"DELETE FROM Tracktable WHERE Albumindex = ?",
			$albumindex
		);
		return true;
	}

	private function set_as_audiobook($albumindex, $value) {
		$result = $this->sql_prepare_query(true, null, null, null, 'UPDATE Tracktable SET isAudiobook = ?, justAdded = 1 WHERE Albumindex = ?', $value, $albumindex);
		return $result;
	}

	private function use_trackimages($albumindex, $value) {
		$result = $this->sql_prepare_query(true, null, null, null, 'UPDATE Albumtable SET useTrackIms = ?, justUpdated = 1 WHERE Albumindex = ?', $value, $albumindex);
		return $result;
	}

	private function forced_uri_only($u,$d) {
		// Some mopidy backends - SoundCloud - can return the same artist/album/track info
		// for multiple different tracks.
		// This gives us a problem because $this->find_item will think they're the same.
		// So for those backends we always force urionly to be true
		logger::core("USERRATINGS", "Checking domain : ".$d);
		if ($u || $d == "soundcloud") {
			return true;
		} else {
			return false;
		}
	}

	private function doCollectionHeader() {
		$this->returninfo['stats'] = $this->collectionStats();
		$this->returninfo['bookstats'] = $this->audiobookStats();
	}

	private function track_is_wishlist($ttid) {
		// Returns boolean false if the TTindex is not in the wishlist or an array of attributes otherwise
		$retval = false;
		$u = $this->simple_query('Uri', 'Tracktable', 'TTindex', $ttid, null);
		if ($u == null) {
			logger::info('BACKEND', "Track",$ttid,"is wishlist. Discarding");
			$meta = $this->get_all_data($ttid);
			$retval = [
				['attribute' => 'Rating', 'value' => $meta['Rating']],
				['attribute' => 'Tags', 'value' => $meta['Tags']],
				['attribute' => 'Playcount', 'value' => $meta['Playcount']]
			];
			$this->returninfo['deletedwishlist'][] = $ttid;
			$this->sql_prepare_query(true, null, null, null,
				"DELETE FROM Playcounttable WHERE TTindex = ?",
				$ttid
			);
			$this->sql_prepare_query(true, null, null, null,
				"DELETE FROM Tracktable WHERE TTindex = ?",
				$ttid
			);
		}
		return $retval;
	}

	private function track_is_hidden($ttid) {
		$h = $this->simple_query('Hidden', 'Tracktable', 'TTindex', $ttid, 0);
		return ($h != 0) ? true : false;
	}

	// private function track_is_searchresult($ttid) {
	// 	// This is for detecting tracks that were added as part of a search, or un-hidden as part of a search
	// 	$h = $this->simple_query('isSearchResult', 'Tracktable', 'TTindex', $ttid, 0);
	// 	return ($h > 1) ? true : false;
	// }

	// private function track_is_unplayable($ttid) {
	// 	$r = $this->simple_query('LinkChecked', 'Tracktable', 'TTindex', $ttid, 0);
	// 	return ($r == 1 || $r == 3);
	// }

	private function check_for_wishlist_track($data) {
		// Searches for a wishlist track based on Title and Artistname
		// Returns false if nothing found or an array of attributes otherwise
		$retval = false;
		$result = $this->sql_prepare_query(false, PDO::FETCH_ASSOC, null, null,
			"SELECT TTindex FROM Tracktable JOIN Artisttable USING (Artistindex)
			WHERE Artistname = ? AND Title = ? AND Uri IS NULL",
		$data['trackartist'],$data['Title']);
		foreach ($result as $obj) {
			logger::info('BACKEND', "Wishlist Track",$obj['TTindex'],"matches the one we're adding");
			$meta = $this->get_all_data($obj['TTindex']);
			$retval = [
				['attribute' => 'Rating', 'value' => $meta['Rating']],
				['attribute' => 'Tags', 'value' => $meta['Tags']],
				['attribute' => 'Playcount', 'value' => $meta['Playcount']]
			];
			$this->returninfo['deletedwishlist'][] = $obj['TTindex'];
			$this->sql_prepare_query(true, null, null, null,
				"DELETE FROM Playcounttable WHERE TTindex = ?",
				$obj['TTindex']
			);
			$this->sql_prepare_query(true, null, null, null,
				"DELETE FROM Tracktable WHERE TTindex = ?",
				$obj['TTindex']
			);
		}
		return $retval;
	}

	private function check_for_hidden_track($ttid) {
		if ($this->track_is_hidden($ttid)) {
			logger::info('SETADATA', 'TTindex',$ttid,'is a hidden track. Copying its playcount then junking it');
			$playcount = $this->simple_query('Playcount', 'Playcounttable', 'TTindex', $ttid, 0);
			$this->sql_prepare_query(true, null, null, null,
				"DELETE FROM Tracktable WHERE TTindex = ?",
				$ttid
			);
			return [['attribute' => 'Playcount', 'value' => $playcount]];
		}
		return false;
	}

	private function get_all_data($ttid) {

		// Misleadingly named function which should be used to get ratings and tags
		// (and whatever else we might add) based on a TTindex
		$data = self::NODATA;
		$result = $this->sql_prepare_query(false, PDO::FETCH_ASSOC, null, [],
			"SELECT
				IFNULL(r.Rating, 0) AS Rating,
				IFNULL(p.Playcount, 0) AS Playcount,
				{$this->sql_to_unixtime('p.LastPlayed')} AS LastTime,
				{$this->sql_to_unixtime('tr.DateAdded')} AS DateAdded,
				IFNULL({$this->get_constant('self::SQL_TAG_CONCAT')}, '') AS Tags,
				tr.isSearchResult,
				tr.Hidden
			FROM
				Tracktable AS tr
				LEFT JOIN Ratingtable AS r ON tr.TTindex = r.TTindex
				LEFT JOIN Playcounttable AS p ON tr.TTindex = p.TTindex
				LEFT JOIN TagListtable AS tl ON tr.TTindex = tl.TTindex
				LEFT JOIN Tagtable AS t USING (Tagindex)
			WHERE tr.TTindex = ?
			GROUP BY tr.TTindex
			ORDER BY t.Name",
			$ttid
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

	private function remove_ttid($ttid) {

		// Remove a track from the database.
		// Doesn't do any cleaning up - call remove_cruft afterwards to remove orphaned artists and albums

		// If it's a search result, it must be a manually added track (we can't delete collection tracks)
		// and we might still need it in the search, so set it to a 2 instead of deleting it.

		logger::info('BACKEND', "Removing track ".$ttid);
		// First, if it is a Search Result, set isSearchResult to 3 if it has a playcount (so it will get hidden on the next search)
		// or to 2 it it doesn't (so it will get removed on the next search)
		$isr = $this->simple_query('isSearchResult', 'Tracktable', 'TTindex', $ttid, 0);
		$pc = $this->simple_query('Playcount', 'Playcounttable', 'TTindex', $ttid, 0);
		// Set isAudiobook = 0 because there was an old comment here about not doing that breaking the UI
		// if we ever re-add it because newly-added tracks go to the collection. Not sure if that's still true;
		if ($isr == 1) {
			if ($pc > 0) {
				logger::log('BACKEND', 'Track is a search result with a playcount so setting isSearchResult to 3');
				if ($this->sql_prepare_query(true, null, null, null, "UPDATE Tracktable SET isSearchResult = 3, isAudiobook = 0 WHERE TTindex = ?", $ttid)) {
					$this->tidy_ratings_and_tags($ttid);
					return true;
				} else {
					return false;
				}
			} else {
				logger::log('BACKEND', 'Track is an existing track search result without a playcount so setting isSearchResult to 2');
				if ($this->sql_prepare_query(true, null, null, null, "UPDATE Tracktable SET isSearchResult = 2, isAudiobook = 0 WHERE TTindex = ?", $ttid)) {
					$this->tidy_ratings_and_tags($ttid);
					return true;
				} else {
					return false;
				}
			}
		} else if ($isr == 2 || $isr == 3) {
			logger::log('BACKEND', 'Track is a search result without a playcount or a hidden track so how the hell did we get here?');
			return false;
		}
		// Second, if it has a Playcount, hide it
		if ($pc > 0) {
			logger::log('BACKEND', 'Hiding Track because it has a playcount');
			if ($this->sql_prepare_query(true, null, null, null, "UPDATE Tracktable SET Hidden = 1, isAudiobook = 0 WHERE TTindex = ?", $ttid)) {
				$this->tidy_ratings_and_tags($ttid);
				return true;
			} else {
				return false;
			}
		}
		// Finally, if none of the above, delete it
		if ($this->sql_prepare_query(true, null, null, null, "DELETE FROM Tracktable WHERE TTindex = ?", $ttid)) {
			$this->tidy_ratings_and_tags($ttid);
			return true;
		}
		return false;
	}

	private function tidy_ratings_and_tags($ttid) {
		$this->sql_prepare_query(true, null, null, null, "DELETE FROM Ratingtable WHERE TTindex = ?", $ttid);
		$this->sql_prepare_query(true, null, null, null, "DELETE FROM TagListtable WHERE TTindex = ?", $ttid);
		$this->sql_prepare_query(true, null, null, null, "DELETE FROM Bookmarktable WHERE TTindex = ?", $ttid);
	}

	public function dummy_returninfo($albumids) {
		foreach ($albumids as $album) {
			logger::trace('RETURNINFO', 'Marking Album', $album, 'as modified');
			$this->sql_prepare_query(true, null, null, null,
				"UPDATE Albumtable SET justUpdated = 1 WHERE Albumindex = ?",
				$album
			);
		}
	}

	public function prepare_returninfo() {
		logger::info("USERRATINGS", "Preparing Return Info");
		$t = microtime(true);

		$sorter = choose_sorter_by_key('aartistroot');
		$lister = new $sorter('aartistroot');
		$lister->get_modified_root_items();
		$lister->get_modified_albums();

		$sorter = choose_sorter_by_key('zartistroot');
		$lister = new $sorter('zartistroot');
		$lister->get_modified_root_items();
		$lister->get_modified_albums();

		$sorter = choose_sorter_by_key('bartistroot');
		if ($sorter) {
			$lister = new $sorter('bartistroot');
			$lister->get_modified_root_items();
			$lister->get_modified_albums();
		}

		$result = $this->generic_sql_query(
			'SELECT Albumindex, AlbumArtistindex, Uri, TTindex, isAudiobook
			FROM Tracktable JOIN Albumtable USING (Albumindex)
			WHERE justAdded = 1 AND Hidden = 0'
		);
		foreach ($result as $mod) {
			logger::log("USERRATING", "  New Track in album ".$mod['Albumindex'].' has TTindex '.$mod['TTindex']);
			$this->returninfo['addedtracks'][] = array(	'artistindex' => $mod['AlbumArtistindex'],
													'albumindex' => $mod['Albumindex'],
													'trackuri' => rawurlencode($mod['Uri']),
													'isaudiobook' => $mod['isAudiobook']
												);
		}
		$at = microtime(true) - $t;
		logger::info("TIMINGS", " -- Finding modified items took ".$at." seconds");
	}

	private function create_new_track(&$data) {

		// create_new_track
		//		Creates a new track, along with artists and album if necessary
		//		Returns: TTindex

		// This is used by the metadata functions for adding new tracks. It is NOT used
		// when doing a search or updating the collection, for reasons explained below.

		// IMPORTANT NOTE
		// The indices
		// albumartist_index, trackartist_index, album_index
		// come through from the frontend via get_extra_track_info() but using them
		// is DANGEROUS because the album and / or artist might no longer exist if the track is
		// the result of a search and another search has been performed since.

		// Does the albumartist exist?
		$data['albumartist_index'] = $this->check_artist($data['albumartist']);

		// Does the track artist exist?
		if ($data['trackartist'] != $data['albumartist']) {
			$data['trackartist_index'] = $this->check_artist($data['trackartist']);
		} else {
			$data['trackartist_index'] = $data['albumartist_index'];
		}

		if ($data['albumartist_index'] === null || $data['trackartist_index'] === null) {
			logger::warn('BACKEND', "Trying to create new track but failed to get an artist index");
			return null;
		}

		if ($data['Album'] == null) {
			if ($data['domain'] == 'ytmusic' || $data['domain'] == 'youtube') {
				logger::warn('BACKEND', 'Album name is not set for',$data['Title'],'- seeing what happens if we allow this');
			} else {
				$data['Album'] = 'rompr_wishlist_'.microtime('true');
			}
		}
		$data['album_index'] = $this->check_album($data);
		if ($data['album_index'] === null) {
			logger::warn('BACKEND', "Trying to create new track but failed to get an album index");
			return null;
		}

		$data['sourceindex'] = null;
		if ($data['file'] === null && $data['streamuri'] !== null) {
			$data['sourceindex'] = $this->check_radio_source($data);
		}

		// Check the track doesn't already exist. This can happen if we're doing an ADD operation and only the URI is different
		// (fucking Spotify). We're not using the ON DUPLICATE KEY UPDATE here because, when that does an UPDATE instead of an INSERT,
		// lastUpdateId() does not return the TTindex of the updated track but rather the current AUTO_INCREMENT value of the table
		// which is about as useful as giving me a forwarding address that only contains the correct continent.

		// We also have to cope with soundcloud, where the same combination of unique keys can actually refer to
		// different tracks. In those circumstances we will have looked up using uri only. As urionly did NOT find a track this
		// means the track we're trying to add must be different. In this case we increment the disc number until we have a unique track.

		while (($bollocks = $this->check_track_exists($data)) !== false) {
			if ($this->forced_uri_only(false, $data['domain'])) {
				$data['Disc']++;
			} else {
				$track = $bollocks[0];
				$cock = false;
				logger::warn('BACKEND', 'Track being added already exists', $data['file'], $track['Uri']);
				$this->sql_prepare_query(true, null, null, null,
					"UPDATE Tracktable SET Uri = ?, Duration = ?, Hidden = ?, Sourceindex = ?, isAudiobook = ?, Genreindex = ?, TYear = ?, LinkChecked = ?, justAdded = ? WHERE TTindex = ?",
					$data['file'],
					$this->best_value($track['Duration'], $data['Time'], $cock),
					$data['hidden'],
					$data['sourceindex'],
					$data['isaudiobook'],
					$this->check_genre($data['Genre']),
					$this->best_value($track['TYear'], $data['year'], $cock),
					0,
					1,
					$track['TTindex']
				);
				return $track['TTindex'];
			}
		}

		if ($this->sql_prepare_query(true, null, null, null,
			"INSERT INTO
				Tracktable
				(Title, Albumindex, Trackno, Duration, Artistindex, Disc, Uri, LastModified, Hidden, Sourceindex, isAudiobook, Genreindex, TYear)
				VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
				$data['Title'],
				$data['album_index'],
				$data['Track'],
				$data['Time'],
				$data['trackartist_index'],
				$data['Disc'],
				$data['file'],
				$data['Last-Modified'],
				$data['hidden'],
				$data['sourceindex'],
				$data['isaudiobook'],
				$this->check_genre($data['Genre']),
				$data['year']
			))
		{
			return $this->mysqlc->lastInsertId();
		} else {
			logger::error('BACKEND', 'FAILED to create new track!');
		}
		return null;
	}

	private function check_track_exists($data) {
		$bollocks = $this->sql_prepare_query(false, PDO::FETCH_ASSOC, null, array(),
			"SELECT * FROM Tracktable WHERE Albumindex = ? AND Artistindex = ? AND TrackNo = ? AND Disc = ? AND Title = ?",
			$data['album_index'], $data['trackartist_index'], $data['Track'], $data['Disc'], $data['Title']
		);
		return (count($bollocks) > 0) ? $bollocks : false;
	}

	private function youtubedl_error($message, $progress_file) {
		logger::error('YOUTUBEDL', $message);
		http_response_code(404);
		file_put_contents($progress_file.'_error', $message);
		print $message;

		if ($progress_file && file_exists($progress_file))
			unlink($progress_file);

		exit(0);
	}

	public function youtubedl_album($data) {
		$urilist = $this->sql_prepare_query(false, PDO::FETCH_COLUMN, 0, [],
				"SELECT Uri FROM Tracktable
				WHERE Albumindex = ?
				AND isAudiobook = ?
				AND Uri LIKE '%:video:%'",
			$data['who'],
			($data['why'] == 'a') ? 0 : 1
		);
		$pfile = $this->youtubedl([
			'urilist' => $urilist,
			'pfile' => 'prefs/youtubedl/dlprogress_'.md5($data['who'])
		]);
		return $pfile;
	}

	public function youtubedl($data) {

		//
		// Important. This function returns the ath of its progress file.
		// If you call this with a list of Uris to download you MUST also pass
		// a progress file. Downloading a list using multiple progress files
		// will break everything.
		//

		$pfile = null;
		$downloader = 'yt-dlp';
		$ytdl_path = find_executable($downloader);
		if ($ytdl_path === false) {
			$downloader = 'youtube-dl';
			$ytdl_path = find_executable($downloader);
			if ($ytdl_path === false)
				$this->youtubedl_error('youtube-dl binary could not be found', null);
		}

		logger::core('YOUTUBEDL', 'youtube-dl is at',$ytdl_path);
		$avconv_path = find_executable('ffmpeg');
		if ($avconv_path === false) {
			if ($downloader == 'yt-dlp')
				$this->youtubedl_error('Could not find ffmpeg - this is required for use with yt-dlp', null);

			$avconv_path = find_executable('avconv');
			if ($avconv_path === false)
				$this->youtubedl_error('Could not find ffmpeg or avconv', null);

		}

		$stufftoget = [];

		foreach ($data['urilist'] as $mopidy_uri) {
			$a = preg_match('/:video\/.*\.(.+)$/', $mopidy_uri, $matches);
			if (!$a)
				$a = preg_match('/:video:(.+)$/', $mopidy_uri, $matches);

			if (!$a)
				$this->youtubedl_error('Could not match URI '.$mopidy_uri, null);

			if ($downloader == 'yt-dlp') {
				logger::info('YOUTUBEDL', 'Using yt-dlp so passing youtube music URI so we can get HQ downlaods');
				$uri_to_get = 'https://music.youtube.com/watch/?v='.$matches[1];
			} else {
				$uri_to_get = 'https://youtu.be/'.$matches[1];
			}
			logger::log('YOUTUBEDL', 'Downloading',$uri_to_get);

			$info = $this->sql_prepare_query(false, PDO::FETCH_ASSOC, null, array(),
				"SELECT
					Title,
					TrackNo,
					ta.Artistname AS trackartist,
					aa.Artistname AS albumartist,
					Albumname
					FROM
					Tracktable
					JOIN Artisttable AS ta USING (Artistindex)
					JOIN Albumtable USING (Albumindex)
					JOIN Artisttable AS aa ON (Albumtable.AlbumArtistindex = aa.Artistindex)
					WHERE Uri = ?
				ORDER BY isSearchResult ASC",
				$mopidy_uri
			);
			if (is_array($info) && count($info) > 0) {
				logger::log('YOUTUBEDL', '  Title is',$info[0]['Title']);
				logger::log('YOUTUBEDL', '  Track Number is',$info[0]['TrackNo']);
				logger::log('YOUTUBEDL', '  Album is',$info[0]['Albumname']);
				logger::log('YOUTUBEDL', '  Track Artist is',$info[0]['trackartist']);
				logger::log('YOUTUBEDL', '  Album Artist is',$info[0]['albumartist']);
			} else {
				logger::info('YOUTUBEDL', '  Could not find title and artist from collection');
			}

			$ttindex = $this->simple_query('TTindex', 'Tracktable', 'Uri', $mopidy_uri, null);
			if ($ttindex === null)
				$this->youtubedl_error('Could not locate that track in the database!', null);

			if (array_key_exists('pfile', $data) && $data['pfile']) {
				$progress_file = $data['pfile'];
			} else {
				$progress_file = 'prefs/youtubedl/dlprogress_'.md5($mopidy_uri);
			}

			if (!file_put_contents($progress_file, "Download Of ".(count($data['urilist']))." Files Starting...\n", FILE_APPEND)) {
				$this->youtubedl_error('Could not open progress file. Possible permissions error', null);
			}

			$stufftoget[] = [
				'mopidy_uri' 	=> $mopidy_uri,
				'uri_to_get' 	=> $uri_to_get,
				'info'		 	=> $info,
				'ttindex'		=> $ttindex,
				'progress_file'	=> $progress_file
			];
		}

		// At this point, terminate the request so the download can run in the background.
		// If we don't do this the browser will retry after 3 minutes and there's nothing we
		// can do about that.
		close_browser_connection();
	    logger::log('YOUTUBEDL', 'Process is now detached from the browser');

		foreach ($stufftoget as $stuff) {

			file_put_contents($stuff['progress_file'], '    Downloading '.$stuff['uri_to_get']."\n", FILE_APPEND);

			$target_dir = 'prefs/youtubedl/'.$stuff['ttindex'];
			while (is_dir($target_dir))
				$target_dir .= '1';

			logger::log('YOUTUBEDL', 'Making Directory ',$target_dir);
			mkdir($target_dir);

			$switches = [
				'-o "'.$target_dir.'/%(title)s-%(id)s.%(ext)s"',
				'--ffmpeg-location "'.$avconv_path.'"',
				'--extract-audio',
				'--write-thumbnail',
				'--restrict-filenames',
				'--newline',
				'--audio-format flac',
				'--audio-quality 0'
			];
			if ($downloader == 'yt-dlp') {
				$switches[] = '--embed-thumbnail';
				$switches[] = '--format bestvideo*+bestaudio/best';
			}
			$cmdline = $ytdl_path.$downloader.' '.implode(' ', $switches).' '.$stuff['uri_to_get'];
			logger::info('YOUTUBEDL', 'Command line is',$cmdline);
			file_put_contents($stuff['progress_file'], $cmdline."\n", FILE_APPEND);
			exec($cmdline.' >> '.$stuff['progress_file'].' 2>&1', $output, $retval);
			if ($retval != 0 && $retval != 1) {
				$this->youtubedl_error('youtube-dl returned error code '.$retval, $stuff['progress_file']);
			}
			$files = glob($target_dir.'/*.flac');
			if (count($files) == 0) {
				$this->youtubedl_error('Could not find downloaded flac file in '.$target_dir, $stuff['progress_file']);
			} else {
				logger::log('YOUTUBEDL', print_r($files, true));
			}

			$info = $stuff['info'];

			if (is_array($info) && count($info) > 0) {
				logger::log('YOUTUBEDL', 'Writiing ID3 tags to',$files[0]);

				$getID3 = new getID3;
				$getID3->setOption(array('encoding'=>'UTF-8'));

				getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'write.php', __FILE__, true);

				$tagwriter = new getid3_writetags;
				$tagwriter->filename       = $files[0];
				$tagwriter->tagformats     = array('metaflac');
				$tagwriter->overwrite_tags = true;
				$tagwriter->tag_encoding   = 'UTF-8';
				$tagwriter->remove_other_tags = true;
				$tags = array(
					'artist' => array(html_entity_decode($info[0]['trackartist'])),
					'albumartist' => array(html_entity_decode($info[0]['albumartist'])),
					'album' => array(html_entity_decode($info[0]['Albumname'])),
					'title' => array(html_entity_decode($info[0]['Title'])),
					'tracknumber' => array($info[0]['TrackNo'])
				);
				$tagwriter->tag_data = $tags;
				if ($tagwriter->WriteTags()) {
					logger::log('YOUTTUBEDL', 'Successfully wrote tags');
					if (!empty($tagwriter->warnings)) {
						logger::warn('YOUTUBEDL', 'There were some warnings'.implode(' ', $tagwriter->warnings));
					}
				} else {
					logger::error('YOUTUBEDL', 'Failed to write tags!', implode(' ', $tagwriter->errors));
				}

				$newname = format_for_disc(html_entity_decode($info[0]['albumartist'])).
					'/'.format_for_disc(html_entity_decode($info[0]['Albumname']));
				logger::log('YOUTUBEDL', 'Trying to mkdir','prefs/youtubedl/'.$newname);
				$newfile = $info[0]['TrackNo'].' - '.format_for_disc(html_entity_decode($info[0]['Title'])).'.flac';
				mkdir('prefs/youtubedl/'.$newname, 0777, true);
				if (is_dir('prefs/youtubedl/'.$newname)) {
					$target_dir = 'prefs/youtubedl/'.$newname;
					logger::log('YOUTUBEDL', 'Moving',$files[0],'to',$target_dir.'/'.$newfile);
					rename($files[0], $target_dir.'/'.$newfile);
					$files[0] = $target_dir.'/'.$newfile;
				}
			}

			copy($stuff['progress_file'], $target_dir.'/download_log.txt');

			$new_uri = dirname(dirname(get_base_url())).'/'.$files[0];
			logger::log('YOUTUBEDL', 'New URI is', $new_uri);
			$this->sql_prepare_query(true, null, null, null,
				"UPDATE Tracktable SET Uri = ? WHERE Uri = ?",
				$new_uri,
				$stuff['mopidy_uri']
			);
			$albumindex = $this->simple_query('Albumindex', 'Tracktable', 'Uri', $new_uri, null);
			$this->sql_prepare_query(true, null, null, null,
				"UPDATE Albumtable SET justUpdated = 1 WHERE Albumindex = ?",
				$albumindex
			);
			$pfile = $stuff['progress_file'];
		}
		return $pfile;
	}

}

?>
