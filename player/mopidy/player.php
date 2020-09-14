<?php
require_once ('player/mpd/mpdinterface.php');
$PLAYER_TYPE = 'mopidyPlayer';
class mopidyPlayer extends base_mpd_player {

	private $monitor;

	public function check_track_load_command($uri) {
		return 'add';
	}

	function musicCollectionUpdate() {
		global $prefs;
		logger::mark("MOPIDY", "Starting Music Collection Update");
		$collection = new musicCollection();
		$this->monitor = fopen('prefs/monitor','w');
		$dirs = $prefs['mopidy_collection_folders'];
		while (count($dirs) > 0) {
			$dir = array_shift($dirs);
			if ($dir == "Spotify Playlists") {
				$this->musicCollectionSpotifyPlaylistHack();
			} else {
				fwrite($this->monitor, "\n<b>".get_int_text('label_scanningf', array($dir))."</b><br />".get_int_text('label_fremaining', array(count($dirs))));
				foreach ($this->parse_list_output('lsinfo "'.format_for_mpd($this->local_media_check($dir)).'"', $dirs, false) as $filedata) {
					$collection->newTrack($filedata);
				}
				$collection->tracks_to_database();
			}
		}
		saveCollectionPlayer('mopidy');
		fwrite($this->monitor, "\nUpdating Database");
	}

	private function musicCollectionSpotifyPlaylistHack() {
		$dirs = array();
		$playlists = $this->do_mpd_command("listplaylists", true, true);
		if (is_array($playlists) && array_key_exists('playlist', $playlists)) {
			$collection = new musicCollection();
			foreach ($playlists['playlist'] as $pl) {
				if (preg_match('/\(by spotify\)/', $pl)) {
					logger::info("COLLECTION", "Ignoring Playlist ".$pl);
				} else {
					logger::log("COLLECTION", "Scanning Playlist ".$pl);
					fwrite($this->monitor, "\n<b>".get_int_text('label_scanningp', array($pl))."</b>");
					foreach ($this->parse_list_output('listplaylistinfo "'.format_for_mpd($pl).'"', $dirs, false) as $filedata) {
						$collection->newTrack($filedata);
					}
					$collection->tracks_to_database();
				}
			}
		}
	}

	public function collectionUpdateDone() {
		fwrite($this->monitor, "\nRompR Is Done");
		fclose($this->monitor);
	}

	private function local_media_check($dir) {
		if ($dir == "Local media") {
			// Mopidy-Local-SQlite contains a virtual tree sorting things by various keys
			// If we scan the whole thing we scan every file about 8 times. This is stoopid.
			// Check to see if 'Local media/Albums' is browseable and use that instead if it is.
			// Using Local media/Folders causes every file to be re-scanned every time we update
			// the collection, which takes ages and also includes m3u and pls stuff that we don't want
			$r = $this->do_mpd_command('lsinfo "'.$dir.'/Albums"', false, false);
			if ($r === false) {
				return $dir;
			} else {
				return $dir.'/Albums';
			}
		}
		return $dir;
	}

	protected function player_specific_fixups(&$filedata) {

		global $prefs;

		if (strpos($filedata['file'], ':artist:') !== false) {
			$this->to_browse[] = $filedata['file'];
			return false;
		} else if (strpos($filedata['file'], ':album:') !== false) {
			$filedata['X-AlbumUri'] = $filedata['file'];
			$filedata['Disc'] = 0;
			$filedata['Track'] = 0;
		}

		switch($filedata['domain']) {
			case 'local':
				// mopidy-local-sqlite sets album URIs for local albums, but sometimes it gets it very wrong
				// We don't need Album URIs for local tracks, since we can already add an entire album
				$filedata['X-AlbumUri'] = null;
				$this->check_undefined_tags($filedata);
				$filedata['folder'] = dirname($filedata['unmopfile']);
				if ($prefs['audiobook_directory'] != '') {
					$f = rawurldecode($filedata['folder']);
					if (strpos($f, $prefs['audiobook_directory']) === 0) {
						$filedata['type'] = 'audiobook';
					}
				}
				break;

			case 'http':
			case 'https':
			case 'mms':
			case 'mmsh':
			case 'mmst':
			case 'mmsu':
			case 'gopher':
			case 'rtp':
			case 'rtsp':
			case 'rtmp':
			case 'rtmpt':
			case 'rtmps':
			case 'dirble':
			case 'tunein':
			case 'radio-de':
			case 'audioaddict':
			case 'oe1':
			case 'bassdrive':
				$this->preprocess_stream($filedata);
				break;

			case "soundcloud":
			case "youtube":
				$this->preprocess_soundcloud($filedata);
				break;

			case "spotify":
				$filedata['folder'] = $filedata['X-AlbumUri'];
				break;

			case "internetarchive":
				$this->check_undefined_tags($filedata);
				$filedata['X-AlbumUri'] = $filedata['file'];
				$filedata['folder'] = $filedata['file'];
				$filedata['AlbumArtist'] = "Internet Archive";
				break;

			case "podcast":
				$filedata['folder'] = $filedata['X-AlbumUri'];
				if ($filedata['Artist'] !== null) {
					$filedata['AlbumArtist'] = $filedata['Artist'];
				}
				if ($filedata['AlbumArtist'] === null) {
					$filedata['AlbumArtist'] = array("Podcasts");
				}
				if (is_array($filedata['Artist']) &&
					($filedata['Artist'][0] == "http" ||
					$filedata['Artist'][0] == "https" ||
					$filedata['Artist'][0] == "ftp" ||
					$filedata['Artist'][0] == "file" ||
					substr($filedata['Artist'][0],0,7) == "podcast")) {
					$filedata['Artist'] = $filedata['AlbumArtist'];
				}
				$filedata['type'] = 'podcast';
				break;

			default:
				$this->check_undefined_tags($filedata);
				$filedata['folder'] = dirname($filedata['unmopfile']);
				break;
		}

		return true;

	}

	private function preprocess_stream(&$filedata) {

		$filedata['Track'] = null;

		list (  $filedata['Title'],
				$filedata['Time'],
				$filedata['Artist'],
				$filedata['Album'],
				$filedata['folder'],
				$filedata['type'],
				$filedata['X-AlbumImage'],
				$filedata['station'],
				$filedata['stream'],
				$filedata['AlbumArtist'],
				$filedata['StreamIndex'],
				$filedata['Comment'],
				$filedata['ImgKey']) = $this->check_radio_and_podcasts($filedata);

		if (strrpos($filedata['file'], '#') !== false) {
			# Fave radio stations added by Cantata/MPDroid
			$filedata['Album'] = substr($filedata['file'], strrpos($filedata['file'], '#')+1, strlen($filedata['file']));
		}

		if (strpos($filedata['file'], 'bassdrive.com') !== false) {
			$filedata['Album'] = 'Bassdrive';
		}

		// Mopidy's podcast backend
		if ($filedata['Genre'] == "Podcast") {
			$filedata['type'] = "podcast";
		}

	}

	private function preprocess_soundcloud(&$filedata) {
		$filedata['folder'] = concatenate_artist_names($filedata['Artist']);
		$filedata['AlbumArtist'] = $filedata['Artist'];
		$filedata['X-AlbumUri'] = $filedata['file'];
		$filedata['Album'] = $filedata['Title'];
		if ($filedata['X-AlbumImage']) {
			$filedata['X-AlbumImage'] = 'getRemoteImage.php?url='.rawurlencode($filedata['X-AlbumImage']);
		}
	}

	private function check_stream_in_collection($url) {
		return sql_prepare_query(false, PDO::FETCH_OBJ, null, null,
			"SELECT
				Tracktable.Title AS title,
				ta.Artistname AS artist,
				Tracktable.Duration AS duration,
				Albumname AS album,
				aa.Artistname AS albumartist,
				Image AS image,
				ImgKey AS imgkey
			FROM Tracktable
				JOIN Artisttable AS ta USING (Artistindex)
				JOIN Albumtable USING (Albumindex)
				JOIN Artisttable AS aa ON (Albumtable.AlbumArtistindex = aa.Artistindex)
			WHERE
				Hidden = 0 AND
				Uri = ?",
			$url
		);
	}

	private function check_radio_and_podcasts($filedata) {

		$url = $filedata['file'];

		// Check for any http files added to the collection or downloaded youtube tracks
		$result = $this->check_stream_in_collection($url);
		foreach ($result as $obj) {
			logger::log("STREAMHANDLER", "Found Track in collection!",$obj->title);
			return array(
				$obj->title,
				$obj->duration,
				array($obj->artist),
				$obj->album,
				md5($obj->album),
				'local',
				$obj->image,
				null,
				'',
				array($obj->albumartist),
				null,
				'',
				$obj->imgkey
			);
		}

		// Do podcasts first. Podcasts played fro TuneIn get added as radio stations, and then if we play that track again
		// via podcasts we want to make sure we pick up the details.

		$result = find_podcast_track_from_url($url);
		foreach ($result as $obj) {
			logger::log("STREAMHANDLER", "Found PODCAST",$obj->title);
			return array(
				($obj->title == '') ? $filedata['Title'] : $obj->title,
				// Mopidy's estimate of the duration is frequently more accurate than that supplied in the RSS
				(array_key_exists('Time', $filedata) && $filedata['Time'] > 0) ? $filedata['Time'] : $obj->duration,
				($obj->artist == '') ? $filedata['Artist'] : array($obj->artist),
				($obj->album == '') ? $filedata['Album'] : $obj->album,
				md5($obj->album),
				'podcast',
				$obj->image,
				null,
				'',
				($obj->albumartist == '') ? $filedata['AlbumArtist'] : array($obj->albumartist),
				null,
				format_text(fixup_links($obj->comment)),
				null
			);
		}

		$result = find_radio_track_from_url($url);
		foreach ($result as $obj) {
			logger::log("STREAMHANDLER", "Found Radio Station ".$obj->StationName);
			// Munge munge munge to make it looks pretty
			if ($obj->StationName != '') {
				logger::trace("STREAMHANDLER", "  Setting Album name from database ".$obj->StationName);
				$album = $obj->StationName;
			} else if ($filedata['Name'] && $filedata['Name'] != 'no name' && strpos($filedata['Name'], ' ') !== false) {
				logger::trace("STREAMHANDLER", "  Setting Album from Name ".$filedata['Name']);
				$album = $filedata['Name'];
			} else if ($filedata['Name'] == null && $filedata['Title'] != null && $filedata['Title'] != 'no name' &&
				$filedata['Artist'] == null && $filedata['Album'] == null && strpos($filedata['Title'], ' ') !== false) {
				logger::trace("STREAMHANDLER", "  Setting Album from Title ".$filedata['Title']);
				$album = $filedata['Title'];
				$filedata['Title'] = null;
			} else {
				logger::log("STREAMHANDLER", "  No information to set Album field");
				$album = ROMPR_UNKNOWN_STREAM;
			}
			return array (
				$filedata['Title'] === null ? '' : $filedata['Title'],
				0,
				$filedata['Artist'],
				$album,
				$obj->PlaylistUrl,
				"stream",
				($obj->Image == '') ? $filedata['X-AlbumImage'] : $obj->Image,
				getDummyStation($url),
				$obj->PrettyStream,
				$filedata['AlbumArtist'],
				$obj->Stationindex,
				array_key_exists('Comment', $filedata) ? $filedata['Comment'] : '',
				null
			);
		}

		logger::warn("STREAMHANDLER", "Stream Track",$filedata['file'],"from",$filedata['domain'],"was not found in database");

		if ($filedata['Album']) {
			$album = $filedata['Album'];
		} else if ($filedata['Name']) {
			logger::trace("STREAMHANDLER", "  Setting Album from Name ".$filedata['Name']);
			$album = $filedata['Name'];
			if ($filedata['Pos'] !== null) {
				update_radio_station_name(array('streamid' => null,'uri' => $filedata['file'], 'name' => $album));
			}
		} else if ($filedata['Name'] == null && $filedata['Title'] != null && $filedata['Artist'] == null && $filedata['Album'] == null) {
			logger::trace("STREAMHANDLER", "  Setting Album from Title ".$filedata['Title']);
			$album = $filedata['Title'];
			$filedata['Title'] = null;
			if ($filedata['Pos'] !== null) {
				update_radio_station_name(array('streamid' => null,'uri' => $filedata['file'], 'name' => $album));
			}
		} else {
			logger::log("STREAMHANDLER", "  No information to set Album field");
			$album = ROMPR_UNKNOWN_STREAM;
		}
		return array(
			$filedata['Title'],
			0,
			$filedata['Artist'],
			$album,
			getStreamFolder(unwanted_array($url)),
			"stream",
			($filedata['X-AlbumImage'] == null) ? '' : $filedata['X-AlbumImage'],
			getDummyStation(unwanted_array($url)),
			null,
			$filedata['AlbumArtist'],
			null,
			array_key_exists('Comment', $filedata) ? $filedata['Comment'] : '',
			null
		);

	}

	public function get_checked_url($url) {
		return array('clicktrack', $url);
	}

	public function get_replay_gain_state() {
		return array();
	}

	public static function is_personal_playlist($playlist) {
		if (strpos($playlist, '(by ') !== false) {
			return false;
		}
		return true;
	}
}

?>