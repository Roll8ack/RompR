<?php
class uibits {
	public static function albumTrack($data) {
		if (substr($data['title'],0,6) == "Album:") return 2;
		if (substr($data['title'],0,7) == "Artist:") {
			logger::warn('ALBUMTRACK', 'Found artist link in album - this should not be here!');
			return 1;
		}

		$d = getDomain($data['uri']);

		if (prefs::$prefs['player_backend'] == "mpd" && $d == "soundcloud") {
			$class = 'clickcue';
		} else {
			$class = 'clicktrack';
		}
		$class .= $data['discclass'];

		if ($data['ismostrecent'])
			$class .= ' mostrecent';

		// Outer container
		if ($data['playable'] == 1 or $data['playable'] == 3) {
			// Note - needs clicktrack and name in case it is a removeable track
			print '<div class="unplayable clicktrack ninesix containerbox padright calign" name="'.rawurlencode($data['uri']).'">';
		} else if ($data['uri'] == null) {
			print '<div class="playable '.$class.' ninesix draggable containerbox padright calign" name="'.$data['ttid'].'">';
		} else {
			print '<div class="playable '.$class.' ninesix draggable containerbox padright calign" name="'.rawurlencode($data['uri']).'">';
		}

		print domainIcon($d, 'collectionicon');

		// Track Number
		if ($data['numtracks'] > 0) {
			print '<div class="tracknumber fixed" style="width:'.strlen($data['numtracks']).'em">';
			if ($data['trackno'] > 0)
				print $data['trackno'];

			print '</div>';
		}

		// Track Title, Artist, and Rating
		if ((string) $data['title'] == "") $data['title'] = urldecode($data['uri']);
		print '<div class="expand containerbox vertical">';

		if ($data['ismostrecent']) {
			print '<div class="fixed playlistrow2">'.language::gettext('label_upnext').'</div>';
		}

		print '<div class="fixed tracktitle">'.$data['title'].'</div>';
		if ($data['artist'] && $data['trackartistindex'] != $data['albumartistindex']) {
			print '<div class="fixed playlistrow2 trackartist">'.$data['artist'].'</div>';
		}
		if ($data['rating']) {
			print '<div class="fixed playlistrow2 trackrating">';
			print '<i class="icon-'.trim($data['rating']).'-stars rating-icon-small"></i>';
			print '</div>';
		}
		if ($data['tags']) {
			print '<div class="fixed playlistrow2 tracktags">';
			print '<i class="icon-tags collectionicon"></i>'.$data['tags'];
			print '</div>';
		}
		print '</div>';

		// Track Duration
		print '<div class="fixed playlistrow2 tracktime">';
		if ($data['time'] > 0) {
			print format_time($data['time']);
		}
		print '</div>';

		// Menu Button
		if ($data['ttid']) {
			$button_class = "icon-plus playlisticonr fixed clickable clickicon invisibleicon clicktrackmenu spinable";
			// lm will be null if ths is a manually added track, but don't give the option to remove from collection
			// if isSearchResult == 3 becaause that's a hidden track that has come up in search results.
			if ($data['lm'] === null && $data['isSearchResult'] != 3) {
				$button_class .= ' clickremovedb';
			}
			if ($data['progress'] > 0) {
				$button_class .= ' clickresetresume';
			}
			if ($d == 'youtube' || $d == 'yt') {
				$button_class .= ' clickyoutubedl';
			}
			print '<div class="'.$button_class.'" rompr_id="'.$data['ttid'].'" rompr_tags="'.rawurlencode($data['tags']).'"></div>';
		}

		print '</div>';

		if ($data['progress'] > 0) {
			print '<input type="hidden" class="resumepos" value="'.$data['progress'].'" />';
			print '<input type="hidden" class="length" value="'.$data['time'].'" />';
		}

		return 0;
	}

	public static function artistHeader($id, $name) {
		$h = '<div class="menu openmenu containerbox menuitem artist" name="'.$id.'">';
		$h .= '<div class="expand">'.$name.'</div>';
		$h .= '</div>';
		return $h;
	}

	public static function browse_artistHeader($id, $name) {
		return '';
	}

	public static function noAlbumsHaeder() {
		print '<div class="playlistrow2" style="padding-left:64px">'.language::gettext("label_noalbums").'</div>';
	}

	public static function albumHeader($obj) {
		$h = '<div class="collectionitem fixed selecotron clearfix">';
		if ($obj['id'] == 'nodrop') {
			// Hacky at the moment, we only use nodrop for streams but here there is no checking
			// because I'm lazy.
			$h .= '<div class="containerbox wrap clickstream playable clickicon '.$obj['class'].'" name="'.rawurlencode($obj['streamuri']).'" streamname="'.$obj['streamname'].'" streamimg="'.$obj['streamimg'].'">';
		} else {
			if (array_key_exists('plpath', $obj)) {
				$h .= '<input type="hidden" name="dirpath" value="'.$obj['plpath'].'" />';
			}
			if ($obj['class'] == 'podcast') {
				$c = ' wrap';
			} else {
				$c = '';
			}
			$h .= '<div class="containerbox'.$c.' openmenu menu '.$obj['class'].'" name="'.$obj['id'].'">';
		}

		$h .= '<div class="helpfulalbum expand">';
		$albumimage = new baseAlbumImage(array('baseimage' => $obj['Image']));
		$h .= $albumimage->html_for_image($obj, 'jalopy', 'medium');

		$h .= '<div class="tagh albumthing">';
		$h .= '<div class="progressbar invisible wafflything"><div class="wafflebanger"></div></div>';
		$h .= '<div class="title-menu">';

		$h .= domainHtml($obj['AlbumUri']);

		$h .= '<div class="artistnamething">'.$obj['Albumname'];
		if ($obj['Year'] && prefs::$prefs['sortbydate']) {
			$h .= ' <span class="notbold">('.$obj['Year'].')</span>';
		}
		$h .= '</div>';
		if ($obj['Artistname']) {
			$h.= '<div class="notbold">'.$obj['Artistname'].'</div>';
		}
		$h .= '</div>';
		$h .= '</div>';

		$h .= '</div>';
		$h .= '</div>';
		$h .= '</div>';

		return $h;
	}

	public static function albumControlHeader($fragment, $why, $what, $who, $artist, $playall = true) {
		// TODO Probably Don't Need This Bit Now
		if ($fragment || $who == 'root') {
			return '';
		}
		$html = '<div class="dropdown-container configtitle fullwidth"><div class="textcentre expand"><b>'.$artist.'</b></div></div>';
		if ($playall) {
			$html .= '<div class="textcentre clickalbum playable brick_wide noselect" name="'.$why.'artist'.$who.'">'.language::gettext('label_play_all').'</div>';
		}
		return $html;
	}

	public static function trackControlHeader($why, $what, $who, $when, $dets) {
		$html = '';
		$iab = -1;
		$play_col_button = 'icon-music';
		$db_album = ($when === null) ? $who : $who.'_'.$when;
		if ($what == 'album' && ($why == 'a' || $why == 'z')) {
			$iab = prefs::$database->album_is_audiobook($who);
			$play_col_button = ($iab == 0) ? 'icon-music' : 'icon-audiobook';
		}
		foreach ($dets as $det) {
			if ($why != '') {
				$html .= '<div class="containerbox wrap album-play-controls">';
				if ($det['AlbumUri']) {
					$albumuri = rawurlencode($det['AlbumUri']);
					if (strtolower(pathinfo($albumuri, PATHINFO_EXTENSION)) == "cue") {
						$html .= '<div class="icon-no-response-playbutton smallicon expand playable clickcue noselect tooltip" name="'.$albumuri.'" title="'.language::gettext('label_play_whole_album').'"></div>';
					} else {
						$html .= '<div class="icon-no-response-playbutton smallicon expand clicktrack playable noselect tooltip" name="'.$albumuri.'" title="'.language::gettext('label_play_whole_album').'"></div>';
						$html .= '<div class="'.$play_col_button.' smallicon expand clickalbum playable noselect tooltip" name="'.$why.'album'.$who.'" title="'.language::gettext('label_from_collection').'"></div>';
					}
				} else {
					$html .= '<div class="'.$play_col_button.' smallicon expand clickalbum playable noselect tooltip" name="'.$why.'album'.$who.'" title="'.language::gettext('label_from_collection').'"></div>';
				}
				$html .= '<div class="icon-single-star smallicon expand clickicon clickalbum playable noselect tooltip" name="ralbum'.$db_album.'" title="'.language::gettext('label_with_ratings').'"></div>';
				$html .= '<div class="icon-tags smallicon expand clickicon clickalbum playable noselect tooltip" name="talbum'.$db_album.'" title="'.language::gettext('label_with_tags').'"></div>';
				$html .= '<div class="icon-ratandtag smallicon expand clickicon clickalbum playable noselect tooltip" name="yalbum'.$db_album.'" title="'.language::gettext('label_with_tagandrat').'"></div>';
				$html .= '<div class="icon-ratortag smallicon expand clickicon clickalbum playable noselect tooltip" name="ualbum'.$db_album.'" title="'.language::gettext('label_with_tagorrat').'"></div>';
				$classes = array();
				if ($why != 'b') {
					if (prefs::$database->num_collection_tracks($who) == 0) {
						$classes[] = 'clickamendalbum clickremovealbum';
					}
					if ($iab == 0) {
						$classes[] = 'clicksetasaudiobook';
					} else if ($iab == 2) {
						$classes[] = 'clicksetasmusiccollection';
					}
				}
				if (array_key_exists('useTrackIms', $det)) {
					if ($det['useTrackIms'] == 1) {
						$classes[] = 'clickunusetrackimages';
					} else {
						$classes[] = 'clickusetrackimages';
					}
				}
				if ($why == 'b' && $det['AlbumUri'] && preg_match('/spotify:album:(.*)$/', $det['AlbumUri'], $matches)) {
					$classes[] = 'clickaddtollviabrowse clickaddtocollectionviabrowse';
					$spalbumid = $matches[1];
				} else {
					$spalbumid = '';
				}
				if (count($classes) > 0) {
					$html .= '<div class="icon-menu smallicon expand clickable clickicon clickalbummenu noselect '.implode(' ',$classes).'" name="'.$who.'" why="'.$why.'" spalbumid="'.$spalbumid.'"></div>';
				}
				$html .= '</div>';
			}
		}
		print $html;
	}

	public static function printDirectoryItem($fullpath, $displayname, $prefix, $dircount, $printcontainer = false) {
		$c = ($printcontainer) ? "searchdir" : "directory";
		print '<input type="hidden" name="dirpath" value="'.rawurlencode($fullpath).'" />';
		print '<div class="'.$c.' menu openmenu containerbox menuitem brick_wide" name="'.$prefix.$dircount.'">';
		print '<i class="icon-folder-open-empty fixed collectionicon"></i>';
		print '<div class="expand">'.htmlentities(urldecode($displayname)).'</div>';
		print '</div>';
		if ($printcontainer) {
			print '<div class="dropmenu" id="'.$prefix.$dircount.'">';
		}
	}

	public static function directoryControlHeader($prefix, $name = null) {
		logger::log('SKIN', 'DCH prefix is',$prefix,'name is',$name);
		if ($name !== null && !preg_match('/^pholder_/', $prefix)) {
			print '<div class="dropdown-container configtitle fullwidth"><div class="textcentre expand"><b>'.$name.'</b></div></div>';
		}
	}

	public static function printRadioDirectory($att, $closeit, $prefix) {
		$name = md5($att['URL']);
		print '<input type="hidden" value="'.rawurlencode($att['URL']).'" />';
		print '<input type="hidden" value="'.rawurlencode($att['text']).'" />';
		print '<div class="menu openmenu '.$prefix.' directory containerbox menuitem brick_wide" name="'.$prefix.'_'.$name.'">';
		print '<i class="icon-folder-open-empty fixed collectionicon"></i>';
		print '<div class="expand">'.$att['text'].'</div>';
		print '</div>';
		// print '<div id="'.$prefix.'_'.$name.'" class="invisible indent containerbox wrap fullwidth notfilled is-albumlist removeable">';
		if ($closeit) {
			print '</div>';
		}
	}

	public static function playlistPlayHeader($name, $text) {
		print '<div class="textcentre clickloadplaylist playable ninesix" name="'.$name.'">'.language::gettext('label_play_all');
		print '<input type="hidden" name="dirpath" value="'.$name.'" />';
		print '</div>';
	}

	public static function addPodcastCounts($html, $extra) {
		$out = phpQuery::newDocument($html);
		$out->find('.containerbox.wrap')->removeClass('wrap')->addClass('vertical');
		$extra = '<div class="helpfulalbum fixed podcastcounts">'.$extra.'</div>';
		$out->find('.containerbox.vertical')->append($extra);
		return $out;
	}

	public static function addUserRadioButtons($html, $index, $uri, $name, $image) {
		$out = phpQuery::newDocument($html);
		$extra = '<div class="fixed containerbox">';
		$extra .= '<div class="expand"></div>';
		$extra .= '<i class="clickable clickradioremove clickicon icon-cancel-circled collectionicon yourradio" name="'.$index.'"></i>';
		$extra .= "</div>";
		$out->find('.helpfulalbum')->append($extra);
		return $out;
	}

	public static function addPlaylistControls($html, $delete, $is_user, $name) {
		$out = phpQuery::newDocument($html);
		if ($delete) {
			$add = ($is_user) ? "user" : "";
			$h = '<div class="fixed containerbox">';
			$h .= '<i class="icon-floppy fixed smallicon clickable clickicon clickrename'.$add.'playlist"></i>';
			$h .= '<input type="hidden" value="'.$name.'" />';
			$h .= '<div class="expand"></div>';
			$h .= '<i class="icon-cancel-circled fixed smallicon clickable clickicon clickdelete'.$add.'playlist"></i>';
			$h .= '<input type="hidden" value="'.$name.'" />';
			$h .= '</div>';
			// $out->find('.helpfulalbum')->append($h);
			phpQuery::newDocument($h)->insertAfter($out->find('img.plimage'));
		}
		return $out;
	}

	public static function albumSizer() {
		print '<div class="sizer"></div>';
	}

}
?>
