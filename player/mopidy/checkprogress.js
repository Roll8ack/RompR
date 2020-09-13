function disable_player_events() {
	mopidysocket.ignoreThings();
}

function enable_player_events() {
	mopidysocket.reactToThings();
}

var AlanPartridge = 30;

// This gives us an event-driven response to Mopidy that works fine alongside our polling-driven
// update methods. Essentially, thi'll pick up any changes that happen that aren't a result of
// interaction with out UI. do_command_list() instructs us to ignore events when it is doing something
// so that the standard mechanism can be used and we don't react twice to everything.

var mopidysocket = function() {

	var socket = null;
	var connected = false;
	var reconnect_timer;
	var react = true;
	var react_timer;
	var error_win = null;

	function socket_closed() {
		debug.warn('MOPISOCKET', 'Socket was closed');
		if (connected) {
			socket_error();
		}
		socket = null;
	}

	function socket_error() {
		if (error_win == null) {
			error_win = infobar.permerror(language.gettext('error_playergone'));
		}
		connected = false;
		mopidysocket.close();
		clearTimeout(reconnect_timer);
		reconnect_timer = setTimeout(mopidysocket.initialise, 10000);
	}

	function socket_open() {
		debug.mark('MOPISOCKET', 'Socket is open');
		clearTimeout(reconnect_timer);
		connected = true;
		if (error_win !== null) {
			infobar.removenotify(error_win);
			error_win = null;
		}
	}

	function socket_message(message) {
		if (react) {
			debug.log('MOPISOCKET', message);
			clearTimeout(react_timer);
			react_timer = setTimeout(update_player, 100);
		}
	}

	async function update_player() {
		debug.log('MOPISOCKET', 'Reacting to message');
		await player.controller.do_command_list([]);
		updateStreamInfo();
	}

	return {
		initialise: async function() {
			if (!connected || !socket || socket.readyState > WebSocket.OPEN) {
				debug.mark('MOPISOCKET', 'Connecting Socket');
				socket = new WebSocket('ws://'+prefs.mpd_host+':'+prefs.mopidy_http_port+'/mopidy/ws');
				socket.onopen = socket_open;
				socket.onerror = socket_error;
				socket.onclose = socket_closed;
				socket.onmessage = socket_message;
			}
			while (socket && socket.readyState == WebSocket.CONNECTING) {
				await new Promise(t => setTimeout(t, 100));
			}
			if (!socket || socket.readyState > WebSocket.OPEN) {
				socket_error();
			}
			return connected;
		},

		close: function() {
			if (connected || socket) {
				socket.close();
			}
		},

		send: async function(data) {
			if (await mopidysocket.initialise()) {
				socket.send(JSON.stringify(data));
			}
		},

		reactToThings: function() {
			react = true;
		},

		ignoreThings: function() {
			react = false;
		}
	}

}();

async function update_on_wake() {
	AlanPartridge = 30;
}

async function checkProgress() {
	await mopidysocket.initialise();
	sleepHelper.addWakeHelper(mopidysocket.initialise);
	sleepHelper.addWakeHelper(update_on_wake);
	while (true) {
		await playlist.is_valid();
		if (AlanPartridge >= 30) {
			AlanPartridge = 0;
			await player.controller.do_command_list([]);
		}
		if (player.status.state == 'play') {
			player.status.progress = (Date.now()/1000) - player.controller.trackstarttime;
		} else {
			player.status.progress = player.status.elapsed;
		}
		var duration = playlist.getCurrent('Time') || 0;
		infobar.setProgress(player.status.progress, duration);
		AlanPartridge++;
		await new Promise(t => setTimeout(t, 1000));
	}
}
