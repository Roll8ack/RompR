function updateStreamInfo() {

	// When playing a stream, mpd returns 'Title' in its status field.
	// This usually has the form artist - track. We poll this so we know when
	// the track has changed (note, we rely on radio stations setting their
	// metadata reliably)

	// Note that mopidy doesn't quite work this way. It sets Title and possibly Name
	// - I fixed that bug once but it got broke again

	if (playlist.getCurrent('type') == "stream") {
		// debug.trace('STREAMHANDLER','Playlist:',playlist.getCurrent('Title'),playlist.getCurrent('Album'),playlist.getCurrent('trackartist'),playlist.getCurrent('type'));
		var temp = playlist.getCurrentTrack();
		if (player.status.Title) {
			var parts = player.status.Title.split(" - ");
			if (parts[0] && parts[1]) {
				temp.trackartist = parts.shift();
				temp.Title = parts.join(" - ");
				temp.metadata.artists = [{name: temp.trackartist, musicbrainz_id: ""}];
				temp.metadata.track = {name: temp.Title, musicbrainz_id: "", artist: temp.trackartist};
			} else if (player.status.Title && player.status.Artist) {
				temp.trackartist = player.status.Artist;
				temp.Title = player.status.Title;
				temp.metadata.artists = [{name: temp.trackartist, musicbrainz_id: ""}];
				temp.metadata.track = {name: temp.Title, musicbrainz_id: "", artist: temp.trackartist};
			}
		}
		if (player.status.Name && !player.status.Name.match(/^\//) && temp.Album == rompr_unknown_stream) {
			// NOTE: 'Name' is returned by MPD - it's the station name as read from the station's stream metadata
			debug.mark('STREAMHANDLER',"Checking For Stream Name Update");
			checkForUpdateToUnknownStream(playlist.getCurrent('StreamIndex'), player.status.Name);
			temp.Album = player.status.Name;
			temp.metadata.album = {name: temp.Album, musicbrainz_id: ""};
		}
		// debug.trace('STREAMHANDLER','Current:',temp.Title,temp.Album,temp.trackartist);
		if (playlist.getCurrent('Title') != temp.Title ||
			playlist.getCurrent('Album') != temp.Album ||
			playlist.getCurrent('trackartist') != temp.trackartist)
		{
			debug.mark("STREAMHANDLER","Detected change of track",temp);
			var aa = new albumart_translator('');
			temp.key = aa.getKey('stream', '', temp.Album);
			playlist.setCurrent({Title: temp.Title, Album: temp.Album, trackartist: temp.trackartist });
			nowplaying.newTrack(temp, true);
		}
	}
}

function checkForUpdateToUnknownStream(streamid, name) {
	// If our playlist for this station has 'Unknown Internet Stream' as the
	// station name, let's see if we can update it from the metadata.
	debug.log("STREAMHANDLER","Checking For Update to Stream",streamid,name, name);
	var m = playlist.getCurrent('Album');
	if (m.match(/^Unknown Internet Stream/)) {
		debug.mark("PLAYLIST","Updating Stream",name);
		yourRadioPlugin.updateStreamName(streamid, name, playlist.getCurrent('file'));
	}
}

function user_playlist_request(data) {
	try {
		fetch(
			'utils/getUserPlaylist.php',
			{
				signal: AbortSignal.timeout(5000),
				cache: 'no-store',
				method: 'POST',
				priority: 'high',
				body: JSON.stringify(data)
			}
		)
		.then((response) => {
			if (!response.ok) {
				throw new Error(response.status+' '+response.statusText);
			}
			self.reloadPlaylists();
		});
	} catch (err) {
		debug.error("CONTROLLER","User playlist request failed", data, err);
	}

}

function playerController() {

	var self = this;
	var plversion = null;
	var oldplname;
	var thenowplayinghack = false;
	var stateChangeCallbacks = new Array();

	this.trackstarttime = 0;
	this.previoussongid = -1;

	this.initialise = async function() {
		debug.mark('PLAYER', 'Initialising');
		let urischemes = data_from_source('player_uri_schemes');
		for (var i in urischemes) {
			var h = urischemes[i].replace(/\:\/\/$/,'');
			debug.log("PLAYER","URI Handler : ",h);
			player.urischemes[h] = true;
		}
		try {
			// checkSearchDomains();
			doMopidyCollectionOptions();
			playlist.radioManager.init();
			await self.do_command_list([]);
			debug.info("MPD","Player is ready");
			infobar.notify(
				"Connected to "+prefs.currenthost+" ("
				+prefs.player_backend.capitalize()
				+" at " + player_ip + ")"
			);
			checkProgress();
			searchManager.add_search_plugin('playersearch', player.controller.search, player.get_search_uri_schemes());
		} catch(err) {
			debug.error("MPD","Failed to connect to player",err);
			infobar.permerror(language.gettext('error_noplayer'));
		}
	}

	this.do_command_list = async function(list) {
		var s;
		debug.debug('PLAYER', 'Command List',list);
		// Prevent checkProgress and radioManager from doing anything while we're doing things
		playlist.invalidate();
		try {
			var response = await fetch(
				'api/player/',
				{
					signal: AbortSignal.timeout(30000),
					body: JSON.stringify(list),
					cache: 'no-store',
					method: 'POST',
					priority: 'high',
				}
			);
			if (response.ok) {
				s = await response.json();
			} else {
				debug.error('CONTROLLER', 'Status was not OK', response);
				var t = await response.text();
				var msg = t ? t : response.status+' '+response.statusText;
				throw new Error(
					language.gettext('error_sendingcommands', [prefs.player_backend])+'<br />'+
					msg
				);
			}
			// Clone the object or we get left with dangling references
			debug.core('PLAYER', 'Got response for',list,s);
			let last_state = player.status.state;
			player.status = structuredClone(s);
			$('#radiodomains').makeDomainChooser("setSelection", player.status.smartradio.radiodomains);
			if (player.status.songid != self.previoussongid) {
				if (playlist.trackHasChanged(player.status.songid)) {
					self.previoussongid = player.status.songid;
				}
			}
			self.trackstarttime = (Date.now()/1000) - player.status.elapsed;
			if (player.status.playlist !== plversion) {
				plversion = player.status.playlist;
				// Repopulate will revalidate the playlist when it completes.
				playlist.repopulate();
			} else {
				playlist.validate();
			}
			if (last_state != player.status.state)
				checkStateChange();
			infobar.updateWindowValues();
			if (player.status.db_updated == 'track') {
				metaHandlers.check_for_db_updates();
			} else if (player.status.db_updated != 'no') {
				podcasts.loadPodcast(player.status.db_updated);
			}
		} catch (err) {
			playlist.validate();
			debug.error('CONTROLLER', 'Command List Failed', err);
			if (list.length > 0) {
				infobar.error(err);
			}
		}
	}

	this.addStateChangeCallback = function(sc) {
		for (var i = 0; i < stateChangeCallbacks.length; i++) {
			if (stateChangeCallbacks[i].state == sc.state && stateChangeCallbacks[i].callback == sc.callback) {
				return;
			}
		}
		stateChangeCallbacks.push(sc);
	}

	function checkStateChange() {
		debug.log('PLAYER', 'State Change Check. State is',player.status.state,'Elapsed is',player.status.elapsed);
		for (var i = 0; i < stateChangeCallbacks.length; i++) {
			if (stateChangeCallbacks[i].state == player.status.state) {
				debug.info('PLAYER', 'Calling state change callback for state',player.status.state);
				stateChangeCallbacks[i].callback();
			}
		}
	}

	this.reloadPlaylists = async function() {
		var openplaylists = [];
		$('#storedplaylists').find('i.menu.openmenu.playlist.icon-toggle-open').each(function() {
			openplaylists.push($(this).attr('name'));
		})
		try {
			await clickRegistry.loadContentIntoTarget({
				target: $("#storedplaylists"),
				clickedElement: $('.choosepanel[name="playlistslist"]'),
				uri: "player/utils/loadplaylists.php"
			});
			for (var i in openplaylists) {
				$('i.menu.openmenu.playlist.icon-toggle-closed[name="'+openplaylists[i]+'"]').click();
			}

			var response = await fetch(
				'player/utils/loadplaylists.php?addtoplaylistmenu=1',
				{
					signal: AbortSignal.timeout(5000),
					cache: 'no-store',
					method: 'GET',
					priority: 'low',
				}
			);
			if (!response.ok) {
				throw new Error(response.status+' '+response.statusText);
			}
			var data = await response.json();
			$('#addtoplaylistmenu').empty();
			data.forEach(function(p) {
				var h = $('<div>', {class: "containerbox backhi clickicon menuitem clickaddtoplaylist", name: p.name }).appendTo($('#addtoplaylistmenu'));
				h.append('<i class="fixed inline-icon icon-doc-text"></i>');
				h.append('<div class="expand">'+p.html+'</div>');
			});
		} catch (err) {
			debug.warn('PLAYER', 'Failed to load playlists', err);
		}
	}

	this.loadPlaylist = function(name) {
		self.do_command_list([['load', name]]);
		return false;
	}

	this.loadPlaylistURL = async function(name) {
		if (name == '') {
			return false;
		}
		var data = {url: name};
		try {
			var response = await fetch(
				'utils/getUserPlaylist.php',
				{
					signal: AbortSignal.timeout(60000),
					cache: 'no-store',
					method: 'POST',
					priority: 'high',
					body: JSON.stringify(data)
				}
			);
			if (response.ok) {
				self.reloadPlaylists();
				self.addTracks([{type: 'remoteplaylist', name: name}], null, null);
			} else {
				throw new Error(response.status+' '+response.statusText);
			}
		} catch (err) {
			debug.error("CONTROLLER","User playlist request failed", data, err);
			playlist.repopulate();
		}
		return false;
	}

	this.deletePlaylist = function(name) {
		self.do_command_list([['rm',decodeURIComponent(name)]]).then(self.reloadPlaylists);
	}

	this.deleteUserPlaylist = function(name) {
		user_playlist_request(
			{
				del: name,
			}
		);
	}

	this.renamePlaylist = function(name, e, callback) {
		oldplname = decodeURIComponent(name);
		debug.log("MPD","Renaming Playlist",name,e);
		var fnarkle = new popup({
			width: 400,
			title: language.gettext("label_renameplaylist"),
			atmousepos: true,
			mousevent: e
		});
		var mywin = fnarkle.create();
		var d = $('<div>',{class: 'containerbox'}).appendTo(mywin);
		var e = $('<div>',{class: 'expand'}).appendTo(d);
		var i = $('<input>',{class: 'enter', id: 'newplname', type: 'text', size: '200'}).appendTo(e);
		var b = $('<button>',{class: 'fixed'}).appendTo(d);
		b.html('Rename');
		fnarkle.useAsCloseButton(b, callback);
		fnarkle.open();
	}

	this.doRenamePlaylist = function() {
		self.do_command_list([["rename", oldplname, $("#newplname").val()]]).then(self.reloadPlaylists);
		return true;
	}

	this.doRenameUserPlaylist = function() {
		user_playlist_request(
			{
				rename: oldplname,
				newname: $("#newplname").val()
			}
		);
		return true;
	}

	this.deletePlaylistTrack = function(playlist,songpos) {
		debug.log('PLAYER', 'Deleting track',songpos,'from playlist',playlist);
		self.do_command_list([['playlistdelete',decodeURIComponent(playlist),songpos]]).then(
			function() {
				playlistManager.loadPlaylistIntoTarget(playlist);
			}
		);
	}

	this.clearPlaylist = async function() {
		// Mopidy does not like removing tracks while they're playing
		if (player.status.state != 'stop')
			await self.do_command_list([['stop']]);
		await self.do_command_list([['clear']]);
	}

	this.savePlaylist = function() {
		var name = $("#playlistname").val();
		debug.log("GENERAL","Save Playlist",name);
		if (name == '') {
			return false;
		} else if (name.indexOf("/") >= 0 || name.indexOf("\\") >= 0) {
			infobar.error(language.gettext("error_playlistname"));
		} else {
			self.do_command_list([["save", name]]).then(function() {
				self.reloadPlaylists();
				infobar.notify(language.gettext("label_savedpl", [name]));
				$("#plsaver").slideToggle('fast');
			});
		}
	}

	this.play = function() {
		alarmclock.pre_play_actions();
		self.do_command_list([['play']]);
	}

	this.pause = function() {
		alarmclock.pre_pause_actions();
		self.do_command_list([['pause']]);
	}

	// play button calls this because our current state could be
	// out of sync if we're a mobile device and we've just woken up.
	// We don't call do_command_list because that initiates a bunch
	// of async stuff that'll be triggered again as soon as this command
	// executes.
	this.toggle_playback_state = async function() {
		try {
			var response = await fetch(
				'api/player/',
				{
					signal: AbortSignal.timeout(30000),
					body: JSON.stringify([]),
					cache: 'no-store',
					method: 'POST',
					priority: 'high',
				}
			);
			if (response.ok) {
				s = await response.json();
			} else {
				throw new Error('Failed to read playback state : '+response.status+' '+response.statusText);
			}
			debug.trace('CONTROLLER', 'Toggling Playback State From',s.state);
			player.status.state = s.state;
			if (s.state == 'play') {
				self.pause();
			} else {
				self.play();
			}
		} catch (err) {
			debug.error('CONTROLLER', 'Error toggling playback', err);
			infobar.error(err);
		}
	}

	this.stop = function() {
		alarmclock.pre_stop_actions();
		self.do_command_list([["stop"]]);
	}

	this.next = function() {
		self.do_command_list([["next"]]);
	}

	this.previous = function() {
		self.do_command_list([["previous"]]);
	}

	this.seek = function(seekto) {
		debug.log("PLAYER","Seeking To",seekto);
		self.do_command_list([["seek", player.status.song, parseInt(seekto.toString())]]);
	}

	this.seekcur = function(seekto) {
		debug.log("PLAYER","Seeking Current To",seekto);
		self.do_command_list([["seekcur", seekto.toString()]]);
	}

	this.playId = function(id) {
		self.do_command_list([["playid",id]]);
	}

	this.playByPosition = function(pos) {
		self.do_command_list([["play",pos.toString()]]);
	}

	this.volume = function(volume, callback) {
		self.do_command_list([["setvol",parseInt(volume.toString())]]).then(function() {
			if (callback) callback();
		});
		return true;
	}

	this.removeId = function(ids) {
		var cmdlist = [];
		$.each(ids, function(i,v) {
			cmdlist.push(["deleteid", v]);
		});
		self.do_command_list(cmdlist);
	}

	this.toggleRandom = function() {
		debug.log('PLAYER', 'Toggling Random');
		var new_value = (player.status.random == 0) ? 1 : 0;
		self.do_command_list([["random",new_value]]).then(playlist.doUpcomingCrap);
	}

	this.toggleCrossfade = function() {
		debug.log('PLAYER', 'Toggling Crossfade');
		var new_value = (player.status.xfade === undefined || player.status.xfade === null ||
			player.status.xfade == 0) ? prefs.crossfade_duration : 0;
		self.do_command_list([["crossfade",new_value]]);
	}

	this.setCrossfade = function(v) {
		self.do_command_list([["crossfade",v]]);
	}

	this.toggleRepeat = function() {
		debug.log('PLAYER', 'Toggling Repeat');
		var new_value = (player.status.repeat == 0) ? 1 : 0;
		self.do_command_list([["repeat",new_value]]);
	}

	this.toggleConsume = function() {
		debug.log('PLAYER', 'Toggling Consume');
		var new_value = (player.status.consume == 0) ? 1 : 0;
		self.do_command_list([["consume",new_value]]);
	}

	// this.takeBackControl = async function(v) {
	// 	await self.do_command_list([["repeat",0],["random", 0],["consume", 1]]);
	// }

	this.addTracks = async function(tracks, playpos, at_pos, queue, return_cmds, pre_cmds) {
		// Call into this to add items to the play queue.
		// tracks  : list of things to add
		// playpos : position to start playback from after adding items or null
		// at_pos  : position to add tracks at (tracks will be added to end then moved to position) or null
		// queue   : true to always queue regardless of CD Player mode

		var abitofahack = true;
		var queue_track = (queue == true) ? true : !prefs.cdplayermode;
		debug.info("MPD","Adding",tracks.length,"Tracks at",at_pos,"playing from",playpos,"queue is",queue);
		var cmdlist = [];
		if (pre_cmds)
			cmdlist = pre_cmds;

		if (!queue_track) {
			cmdlist.push(['stop']);
			cmdlist.push(['clear']);
		}
		$.each(tracks, function(i,v) {
			debug.debug('MPD', v);
			switch (v.type) {
				case "uri":
					if (queue_track) {
						cmdlist.push(['add',v.name]);
					} else {
						cmdlist.push(['addtoend', v.name]);
					}
					break;

				case "playlist":
				case "cue":
					cmdlist.push(['load',v.name]);
					break;

				case "item":
					cmdlist.push(['additem',v.name]);
					break;

				case "playalbumtag":
					cmdlist.push(['playalbumtag',v.name,v.album, v.why]);
					break;

				case "podcasttrack":
					cmdlist.push(['add',v.name]);
					break;

				case "stream":
					cmdlist.push(['loadstreamplaylist',v.url,v.image,v.station]);
					break;

				case "playlisttrack":
					if (queue_track) {
						cmdlist.push(['add',v.name]);
					} else {
						cmdlist.push(['playlisttoend',v.playlist,v.frompos]);
					}
					break;

				case "resumepodcast":
					var is_already_in_playlist = playlist.findIdByUri(v.uri);
					if (is_already_in_playlist !== false && queue_track) {
						cmdlist.push(['playid', is_already_in_playlist]);
						cmdlist.push(['seekpodcast', is_already_in_playlist, v.resumefrom]);
					} else {
						var pos = queue_track ? playlist.getfinaltrack()+1 : 0;
						var to_end = queue_track ? 'no' : 'yes';
						cmdlist.push(['resume', v.uri, v.resumefrom, pos, to_end]);
						if (at_pos === null) {
							playlist.waiting();
						}
					}
					// Don't add the play command if we're doing a resume,
					// because api/player/ will add it and this will override it
					abitofahack = false;
					playpos = null;
					break;

				case 'remoteplaylist':
					cmdlist.push(['addremoteplaylist', v.name]);
					break;
			}
		});

		// Used by alarm clock. Need to return here as we don't
		// know what playpos is going to be when the alarm goes off
		if (return_cmds)
			return cmdlist;

		if (abitofahack && !queue_track) {
			cmdlist.push(['play']);
		} else if (playpos !== null) {
			cmdlist.push(['play', playpos.toString()]);
		}
		if (at_pos === 0 || at_pos) {
			cmdlist.push(['moveallto', at_pos]);
		}
		if (at_pos === null && abitofahack) {
			playlist.waiting();
		}
		await self.do_command_list(cmdlist);
	}

	this.move = function(first, num, moveto) {
		var itemstomove = first.toString();
		if (num > 1) {
			itemstomove = itemstomove + ":" + (parseInt(first)+parseInt(num));
		}
		if (itemstomove == moveto) {
			// This can happen if you drag the final track from one album to a position below the
			// next album's header but before its first track. This doesn't change its position in
			// the playlist but the item in the display will have moved and we need to move it back.
			playlist.repopulate();
		} else {
			debug.log("PLAYER", "Move command is move&arg="+itemstomove+"&arg2="+moveto);
			self.do_command_list([["move",itemstomove,moveto]]);
		}
	}

	this.stopafter = function() {
		var cmds = [];
		if (player.status.repeat == 1) {
			cmds.push(["repeat", 0]);
		}
		cmds.push(["single", 1]);
		self.do_command_list(cmds);
	}

	this.cancelSingle = function() {
		self.do_command_list([["single",0]]);
	}

	this.doOutput = function(id) {
		state = $('#outputbutton_'+id).is(':checked');
		if (state) {
			self.do_command_list([["disableoutput",id]]);
		} else {
			self.do_command_list([["enableoutput",id]]);
		}
	}

	this.doMute = function() {
		debug.log('PLAYER', 'Toggling Mute');
		if (prefs.player_backend == "mopidy") {
			if ($("#mutebutton").hasClass('icon-output-mute')) {
				$("#mutebutton").removeClass('icon-output-mute').addClass('icon-output');
				self.do_command_list([["disableoutput", 0]]);
			} else {
				$("#mutebutton").removeClass('icon-output').addClass('icon-output-mute');
				self.do_command_list([["enableoutput", 0]]);
			}
		} else {
			if ($("#mutebutton").hasClass('icon-output-mute')) {
				$("#mutebutton").removeClass('icon-output-mute').addClass('icon-output');
				self.do_command_list([["enableoutput", 0]]);
			} else {
				$("#mutebutton").removeClass('icon-output').addClass('icon-output-mute');
				self.do_command_list([["disableoutput", 0]]);
			}
		}
	}

	this.search = async function(terms, domains) {
		if (player.updatingcollection) {
			infobar.notify(language.gettext('error_nosearchnow'));
			return false;
		}
		var st = {
			command: 'search',
			resultstype: (prefs.sortresultsby == 'results_as_tree') ? 'tree' : 'collection',
			domains: domains,
			dump: collectionHelper.collectionKey('b')
		};
		debug.log("PLAYER","Doing Search:",terms,st);
		if ((Object.keys(terms).length == 1 && (terms.tag || terms.rating)) ||
			(Object.keys(terms).length == 2 && (terms.tag && terms.rating)) ||
			((terms.tag || terms.rating) && !(terms.composer || terms.performer || terms.any))) {
			// Use the sql search engine if we're looking only for things it supports
			debug.log("PLAYER","Searching using database search engine");
			st.terms = terms;
		} else {
			st.mpdsearch = terms;
		}
		await clickRegistry.loadContentIntoTarget({
			type: 'POST',
			target: $('#searchresultholder'),
			clickedElement: $('button[name="globalsearch"]'),
			uri: 'api/collection/',
			data: st
		});
		searchManager.make_search_title('searchresultholder', 'Music');

	}

	this.postLoadActions = function() {
		if (player.status.songid != self.previoussongid) {
			if (playlist.trackHasChanged(player.status.songid)) {
				self.previoussongid = player.status.songid;
			}
		}
		if (thenowplayinghack) {
			// The Now PLaying Hack is so that when we switch the option for
			// 'display composer/performer in nowplaying', we can first reload the
			// playlist (to get the new artist metadata keys from the backend)
			// and then FORCE nowplaying to accept a new track with the same backendid
			// as the previous - this forces the nowplaying info to update
			thenowplayinghack = false;
			nowplaying.newTrack(playlist.getCurrentTrack(), true);
		}
	}

	this.doTheNowPlayingHack = function() {
		debug.log("MPD","Doing the nowplaying hack thing");
		thenowplayinghack = true;
		playlist.repopulate();
	}

	this.replayGain = function(event) {
		var x = $(event.target).attr("id").replace('replaygain_','');
		debug.log("MPD","Setting Replay Gain to",x);
		self.do_command_list([["replay_gain_mode",x]]);
	}

	this.addTracksToPlaylist = function(playlist,tracks,moveto,playlistlength) {
		debug.debug('PLAYER','Tracks is',tracks);
		debug.log("PLAYER","Adding tracks to playlist",playlist,"then moving to",moveto,"playlist length is",playlistlength);
		var cmds = new Array();
		for (var i in tracks) {
			if (tracks[i].uri) {
				debug.trace('PLAYER', 'Adding URI', tracks[i].uri);
				cmds.push(['playlistadd',decodeURIComponent(playlist),tracks[i].uri,
					moveto,playlistlength]);
			} else if (tracks[i].dir) {
				cmds.push(['playlistadddir',decodeURIComponent(playlist),tracks[i].dir,
					moveto,playlistlength]);
			}
		}
		self.do_command_list(cmds).then(
			function() {
				playlistManager.loadPlaylistIntoTarget(playlist);
			}
		);
	}

	this.movePlaylistTracks = function(playlist,from,to) {
		debug.log('CONTROLLER', 'Playlist Move',playlist,from, to);
		var cmds = new Array();
		cmds.push(['playlistmove',decodeURIComponent(playlist),from,to]);
		self.do_command_list(cmds).then(
			function() {
				playlistManager.loadPlaylistIntoTarget(playlist);
			}
		);
	}

}
