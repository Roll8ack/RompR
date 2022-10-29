var info_lyrics = function() {

	var me = "lyrics";

	return {
		getRequirements: function(parent) {
			return ['file'];
		},

		collection: function(parent, artistmeta, albummeta, trackmeta) {

			debug.debug("LYRICS PLUGIN", "Creating data collection");

			var self = this;

			function formatLyrics(data) {
				debug.trace("LYRICS PLUGIN","Formatting Lyrics");
				if (data) {
					data = data.replace(/^(\w)/, '<font size="120%">$1</font>')
					data = data.replace(/\n/g, '<br>');
				}
				return '<div class="lyrics"><h2 align="center">'+language.gettext("lyrics_lyrics")+'</h2><p>'+data+'</p></div>';
			}

			function getSearchArtist() {
				return (albummeta.artist && albummeta.artist != "") ? albummeta.artist : parent.playlistinfo.trackartist;
			}

			this.startAfterSpecial = function() {

			}

			this.tryReadingTags = function() {
				if (prefs.music_directory_albumart == "") {
					trackmeta.lyrics.lyrics = '<h3 align=center>'+language.gettext("lyrics_nonefound")+'</h3><p>'+language.gettext("lyrics_nopath")+'</p>';
					self.doBrowserUpdate();
				} else {
					$.post("browser/backends/getLyrics.php", {file: player.status.file, artist: getSearchArtist(), song: trackmeta.name})
						.done(function(data) {
							debug.debug("LYRICS",data);
							trackmeta.lyrics.lyrics = data;
							self.doBrowserUpdate();
						});
				}
			}

			this.populate = async function() {

				// info.file will populate the lyrics metadata. Don't do it here
				parent.updateData({
					lyrics: {
						lyrics: ''
					},
					triggers: {
						lyrics: {
							lyrics: self.do_lyrics
						}
					}
				}, trackmeta);

				parent.updateData({
						lyrics: {}
					},
					artistmeta
				);

				parent.updateData({
						lyrics: {}
					},
					albummeta
				);

				if (typeof artistmeta.lyrics.layout == 'undefined')
					artistmeta.lyrics.layout = new info_layout_empty();

				if (typeof albummeta.lyrics.layout == 'undefined')
					albummeta.lyrics.layout = new info_layout_empty();

				if (typeof trackmeta.lyrics.layout == 'undefined')
					trackmeta.lyrics.layout = new info_html_layout({title: trackmeta.name, type: 'track', source: me});

				self.do_lyrics();

			}

			this.do_lyrics = function() {
				if (trackmeta.lyrics.lyrics === null) {
					self.tryReadingTags();
				} else {
					self.doBrowserUpdate();
				}
			}

			this.doBrowserUpdate = function() {
				trackmeta.lyrics.layout.finish(null, null, formatLyrics(trackmeta.lyrics.lyrics));
			}
		}

	}


}();

nowplaying.registerPlugin("lyrics", info_lyrics, "icon-doc-text-1", "button_lyrics");
