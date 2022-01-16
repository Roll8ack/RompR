var starRadios = function() {

	return {

		setup: function() {

			//
			// 1 star etc
			//
			for (var i = 1; i <= 5; i++) {
				$('#pluginplaylists').append(playlist.radioManager.standardBox('starRadios', i+'stars', 'icon-'+i+'-stars', language.gettext('playlist_xstar', [i])));
			}

			//
			// Tag
			//
			var a = $('<div>', {class: "menuitem fullwidth"}).appendTo('#pluginplaylists');
			var c = $('<div>', {class: "containerbox expand spacer vertical-centre"}).
				appendTo(a).makeTagMenu({
				textboxname: 'cynthia',
				placeholder: 'Tag',
				labelhtml: '<i class="icon-tags svg-square"></i>',
				populatefunction: tagAdder.populateTagMenu,
				buttontext: language.gettext('button_playradio'),
				buttonfunc: starHelpers.tagPopulate
			});

			//
			// Genre
			//
			var a = $('<div>', {class: "menuitem fullwidth"}).appendTo('#pluginplaylists');
			var c = $('<div>', {class: "containerbox expand spacer vertical-centre"}).
				appendTo(a).makeTagMenu({
				textboxname: 'farrago',
				placeholder: 'Genre',
				labelhtml: '<i class="icon-music svg-square"></i>',
				populatefunction: starHelpers.populateGenreMenu,
				buttontext: language.gettext('button_playradio'),
				buttonfunc: starHelpers.genrePopulate
			});

			//
			// Artist
			//
			var a = $('<div>', {class: "menuitem fullwidth"}).appendTo('#pluginplaylists');
			var c = $('<div>', {class: "containerbox expand spacer vertical-centre"}).
				appendTo(a).makeTagMenu({
				textboxname: 'bobblehat',
				placeholder: 'Tracks By Artist',
				labelhtml: '<i class="icon-artist svg-square"></i>',
				populatefunction: starHelpers.populateArtistMenu,
				buttontext: language.gettext('button_playradio'),
				buttonfunc: starHelpers.artistPopulate
			});

			//
			// All Tracks at random
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('starRadios', 'allrandom', 'icon-allrandom', language.gettext('label_allrandom')));

			//
			// Never Played Tracks
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('starRadios', 'neverplayed', 'icon-neverplayed', language.gettext('label_neverplayed')));

			//
			// Recently Played Tracks
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('starRadios', 'recentlyplayed', 'icon-recentlyplayed', language.gettext('label_recentlyplayed')));

		}

	}
}();

var starHelpers = function() {
	return {

		tagPopulate: function() {
			playlist.radioManager.load('starRadios', 'tag+'+$('[name="cynthia"]').val());
		},

		genrePopulate: function() {
			playlist.radioManager.load('starRadios', 'genre+'+$('[name="farrago"]').val());
		},


		artistPopulate: function() {
			playlist.radioManager.load('starRadios', 'artist+'+$('[name="bobblehat"]').val());
		},

		populateGenreMenu: function(callback) {
			metaHandlers.genericQuery(
				'getgenres',
				callback,
				function() {
					debug.error("DB TRACKS", "Failed to get genres");
				}
			);
		},

		populateArtistMenu: function(callback) {
			metaHandlers.genericQuery(
				'getartists',
				callback,
				function() {
					debug.error("DB TRACKS", "Failed to get artists");
				}
			);
		},

		populateAlbumArtistMenu: function(callback) {
			metaHandlers.genericQuery(
				'getalbumartists',
				callback,
				function() {
					debug.error("DB TRACKS", "Failed to get artists");
				}
			);
		}

	}
}();

var recentlyaddedtracks = function() {

	return {

		setup: function() {

			//
			// Recently Added Tracks
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('recentlyaddedtracks', 'recentlyadded_random', 'icon-recentlyplayed', language.gettext('label_recentlyadded_random')));
			$('#pluginplaylists').append(playlist.radioManager.standardBox('recentlyaddedtracks', 'recentlyadded_byalbum', 'icon-recentlyplayed', language.gettext('label_recentlyadded_byalbum')));
		}
	}
}();

var mostPlayed = function() {

	return {

		setup: function() {

			//
			// Favourite Tracks
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('mostPlayed', 'mostplayed', 'icon-music', language.gettext('label_mostplayed')));
		}
	}
}();

var faveAlbums = function() {

	return {

		setup: function() {

			//
			// Favourite Albums
			//
			$('#pluginplaylists').append(playlist.radioManager.standardBox('faveAlbums', 'favealbums', 'icon-music', language.gettext('label_favealbums')));
		}
	}
}();

playlist.radioManager.register("starRadios", starRadios, 'radios/code/starRadios.js');
playlist.radioManager.register("mostPlayed", mostPlayed, 'radios/code/mostplayed.js');
playlist.radioManager.register("faveAlbums", faveAlbums, 'radios/code/favealbums.js');
playlist.radioManager.register("recentlyaddedtracks", recentlyaddedtracks, 'radios/code/recentlyadded.js');
