var albumstolistento = function() {

	var atl = null;
	var maxwidth = 640;
	var holder;
	var spinner;

	function getListenLater() {
		metaHandlers.genericQuery('getlistenlater', gotListenLater, notGotListenLater);
	}

	function notGotListenLater() {
		debug.error("LISTENLATER","Failed to get list");
	}

	function gotListenLater(data) {
		spinner.remove();
		if (data.length == 0) {
			holder.append('<h3 align="center">'+language.gettext('no_albumtolistento')+'</h3>');
		} else {
			holder.spotifyAlbumThing({
				classes: 'brick tagholder2 selecotron',
				itemselector: 'brick',
				sub: 'album',
				showbiogs: true,
				maxwidth: maxwidth,
				is_plugin: true,
				imageclass: 'jalopy',
				showlistenlater: false,
				showremovebutton: true,
				removecallback: albumstolistento.removeId,
				data: data
			});
			setDraggable('#atlfoldup');
		}
	}

	function makeHolder() {
		holder = $('<div>', {id: 'albumstolistento', class: 'holdingcell masonified2 helpfulholder noselection'}).appendTo('#atlfoldup');
	}

	return {

		open: function() {
			if (atl == null) {
				debug.log("LISTENLATER","Opening....");
				atl = browser.registerExtraPlugin("atl", language.gettext("label_albumstolistento"), albumstolistento, 'https://fatg3erman.github.io/RompR/Albums-To-Listen-To');
				spinner = $('<div>').appendTo('#atlfoldup');
				spinner.append('<i class="svq-square icon-spin6 spinner"></i>');
				makeHolder();
				getListenLater();
				atl.slideToggle('fast');
				browser.goToPlugin("atl");
				browser.rePoint();
			} else {
				browser.goToPlugin('atl');
			}
		},

		close: function() {
			holder.remove();
			atl = null;
		},

		handleClick: function(element, event) {
			if (element.hasClass('clickspotifywidget')) {
				holder.spotifyAlbumThing('handleClick', element);
			}
		},

		update: function() {
			holder.remove();
			makeHolder();
			getListenLater();
		},

		removeId: function(id) {
			metaHandlers.genericQuery({action: 'removelistenlater', index: id}, function() {
				debug.log("LISTENLATER", "Listen Later ID",id,"removed");
			}, function() {
				debug.error("LISTENLATER", "Failed To Remove ID",id);
			});
		}

	}

}();

pluginManager.setAction(language.gettext("label_albumstolistento"), albumstolistento.open);
albumstolistento.open();
