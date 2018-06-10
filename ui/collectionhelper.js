var collectionHelper = function() {

    var monitortimer = null;
    var monitorduration = 1000;
    var update_load_timer = null;
    var update_load_timer_running = false;
    var returned_data = new Array();
    var update_timer = null;

    function scanFiles(cmd) {
        collectionHelper.prepareForLiftOff(language.gettext("label_updating"));
        collectionHelper.markWaitFileList(language.gettext("label_updating"));
        uiHelper.emptySearchResults();
        debug.log("PLAYER","Scanning Files",cmd,prefs.player_backend,prefs.mopidy_scan_command);
        if (prefs.player_backend == "mopidy" && prefs.mopidy_scan_command != '') {
            debug.shout("PLAYER","Scanning Mopidy using external scan command");
            $.getJSON("player/mopidy/mopidyscan.php?scan=yes", function() {
                update_load_timer = setTimeout( pollAlbumList, 2000);
                update_load_timer_running = true;
            });
        } else {
            debug.shout("PLAYER","Scanning using",cmd);
            player.controller.do_command_list([[cmd]], function() {
                update_load_timer = setTimeout( pollAlbumList, 2000);
                update_load_timer_running = true;
            });
        }
    }

    function pollAlbumList() {
        if(update_load_timer_running) {
            clearTimeout(update_load_timer);
            update_load_timer_running = false;
        }
        if (prefs.player_backend == "mopidy" && prefs.mopidy_scan_command != '') {
            $.getJSON("player/mopidy/mopidyscan.php?check=yes", checkPoll);
        } else {
            $.getJSON("player/mpd/postcommand.php", checkPoll);
        }
    }

    function checkPoll(data) {
        if (data.updating_db) {
            update_load_timer = setTimeout( pollAlbumList, 1000);
            update_load_timer_running = true;
        } else {
            if (prefs.hide_filelist && !prefs.hide_albumlist) {
                loadCollection('albums.php?rebuild=yes&dump='+collectionHelper.collectionKey('a'), null);
            } else if (prefs.hidealbumlist && !prefs.hide_filelist) {
                loadCollection(null, 'dirbrowser.php');
            } else if (!prefs.hidealbumlist && !prefs.hide_filelist) {
                loadCollection('albums.php?rebuild=yes&dump='+collectionHelper.collectionKey('a'), 'dirbrowser.php');
            }
        }
    }

    function loadCollection(albums, files) {
        if (albums != null) {
            debug.log("GENERAL","Loading Collection from URL",albums);
            $.ajax({
                type: "GET",
                url: albums,
                timeout: 800000,
                dataType: "html",
                success: function(data) {
                    clearTimeout(monitortimer);
                    $("#collection").html(data);
                    if ($('#emptycollection').length > 0) {
                        if (!$('#collectionbuttons').is(':visible')) {
                            toggleCollectionButtons();
                        }
                        $('[name="donkeykong"]').makeFlasher({flashtime: 0.5, repeats: 3});
                    }
                    data = null;
                    player.collectionLoaded = true;
                    player.updatingcollection = false;
                    if (albums.match(/rebuild/)) {
                        infobar.notify(infobar.NOTIFY,"Music Collection Updated");
                        collectionHelper.scootTheAlbums($("#collection"));
                    }
                    layoutProcessor.postAlbumActions($('#collection'));
                },
                error: function(data) {
                    clearTimeout(monitortimer);
                    $("#collection").html(
                        '<p align="center"><b><font color="red">Failed To Generate Collection :</font>'+
                        '</b><br>'+data.responseText+"<br>"+data.statusText+"</p>");
                    debug.error("PLAYER","Failed to generate albums list",data);
                    infobar.notify(infobar.ERROR,"Music Collection Update Failed");
                    player.updatingcollection = false;
                }
            });
            monitortimer = setTimeout(checkUpdateMonitor,monitorduration);
        }
        if (files != null) {
            debug.log("GENERAL","Loading File Browser from URL",files);
            $("#filecollection").load(files);
        }
    }
    
    function checkUpdateMonitor() {
        $.ajax({
            type: "GET",
            url: 'utils/checkupdateprogress.php',
            dataType: 'json',
            success: function(data) {
                debug.debug("UPDATE",data);
                $('#updatemonitor').html(data.current);
                if (player.updatingcollection) {
                    monitortimer = setTimeout(checkUpdateMonitor,monitorduration);
                }
            },
            error: function(data) {
                debug.log("UPDATE","ERROR",data);
                if (player.updatingcollection) {
                    monitortimer = setTimeout(checkUpdateMonitor,monitorduration);
                }
            }
        });
    }
    
    function updateUIElements() {
        
        if (dbQueue.outstandingRequests() > 0) {
            debug.log("UI","Deferring updates due to outstanding requests");
            clearTimeout(update_timer);
            setTimeout(updateUIElements, 1000);
            return;
        }
        
        returned_data.forEach(function(rdata, index) {

            if (rdata && rdata.hasOwnProperty('deletedalbums')) {
                $.each(rdata.deletedalbums, function(i, v) {
                    debug.log("REMOVING", "Album", v);
                    uiHelper.removeAlbum('aalbum'+v);
                });
            }

            if (rdata && rdata.hasOwnProperty('deletedartists')) {
                $.each(rdata.deletedartists, function(i, v) {
                    debug.log("REMOVING", "Artist", v);
                    uiHelper.removeArtist(v);
                });
            }

            if (rdata && rdata.hasOwnProperty('modifiedalbums')) {
                $('#emptycollection').remove();
                $.each(rdata.modifiedalbums, function(i,v) {
                    // We remove and replace any modified albums, as they may have a new date or albumartist which would cause
                    // them to appear elsewhere in the collection. First remove the dropdown if it exists and replace its contents
                    debug.log("MODIFIED","Album",v.id);
                    uiHelper.insertAlbum(v);
                });
            }

            if (rdata && rdata.hasOwnProperty('modifiedartists')) {
                $('#emptycollection').remove();
                $.each(rdata.modifiedartists, function(i,v) {
                    // The only thing to do with artists is to add them in if they don't exist
                    // NOTE. Do this AFTER inserting new albums, because if we're doing albumbyartist with banners showing
                    // then the insertAfter logic will be wrong if we've already inserted the artist banner. We also need
                    // to remove and replace the banner when that sort option is used, because we only insertAfter an album ID
                    if (prefs.sortcollectionby == 'albumbyartist') {
                        $("#aartist"+v.id).remove();
                    }
                    var x = uiHelper.findArtistDisplayer('aartist'+v.id);
                    if (x.length == 0) {
                        uiHelper.insertArtist(v);
                    }
                });
            }

            if (rdata && rdata.hasOwnProperty('addedtracks') && rdata.addedtracks.length > 0) {
                $.each(rdata.addedtracks, function(i, v) {
                    if (v.albumindex !== null && v.trackuri != '') {
                        // (Ignore if it went into the wishlist)
                        debug.log("INSERTED","Displaying",v);
                        layoutProcessor.displayCollectionInsert(v);
                    }
                });
            } else {
                infobar.markCurrentTrack();
            }

            if (rdata && rdata.hasOwnProperty('stats')) {
                // stats is another html fragment which is the contents of the
                // statistics box at the top of the collection
                $("#fothergill").html(rdata.stats);
            }
        
            returned_data[index] = null;
        
        });

        collectionHelper.scootTheAlbums($("#collection"));

    }
        
    return {

        forceCollectionReload: function() {
            collection_status = 0;
            collectionHelper.checkCollection(false, false);
        },
    
        prepareForLiftOff: function(text) {
            infobar.notify(infobar.PERMNOTIFY,text);
            $("#collection").empty();
            doSomethingUseful('collection', text);
            var x = $('<div>',{ id: 'updatemonitor', class: 'tiny', style: 'padding-left:1em;margin-right:1em'}).insertAfter($('#spinner_collection'));
        },
        
        markWaitFileList: function(text) {
            $("#filecollection").empty();
            doSomethingUseful("filecollection", text);
        },
        
        collectionKey: function(w) {
            return w+prefs.sortcollectionby+'root';
        },

        checkCollection: function(forceup, rescan) {
            if (forceup && player.updatingcollection) {
                infobar.notify(infobar.ERROR, "Already Updating Collection!");
                return;
            }
            var update = forceup;
            if (prefs.updateeverytime) {
                debug.mark("GENERAL","Updating Collection due to preference");
                update = true;
            } else {
                if (!prefs.hide_albumlist && collection_status == 1) {
                    debug.mark("GENERAL","Updating Collection because it is out of date");
                    collection_status = 0;
                    update = true;
                }
            }
            if (update) {
                player.updatingcollection = true;
                $("#searchresultholder").html('');
                scanFiles(rescan ? 'rescan' : 'update');
            } else {
                if (prefs.hide_filelist && !prefs.hide_albumlist) {
                    loadCollection('albums.php?item='+collectionHelper.collectionKey('a'), null);
                } else if (prefs.hide_albumlist && !prefs.hide_filelist) {
                    loadCollection(null, 'dirbrowser.php');
                } else if (!prefs.hide_albumlist && !prefs.hide_filelist) {
                    loadCollection('albums.php?item='+collectionHelper.collectionKey('a'), 'dirbrowser.php');
                }
            }
        },
        
        scootTheAlbums: function(jq) {
            if (prefs.downloadart) {
                $.each(jq.find("img.notexist"), function() {
                    coverscraper.GetNewAlbumArt({imgkey: $(this).attr('name')});
                });
            }
        },
        
        updateCollectionDisplay: function(rdata) {
            // rdata contains HTML fragments to insert into the collection
            // Otherwise we would have to reload the entire collection panel every time,
            // which would cause any opened dropdowns to be mysteriously closed,
            // which would just look shit.
            debug.trace("RATING PLUGIN","Update Display",rdata);
            if (rdata) {
                returned_data.push(rdata);
                clearTimeout(update_timer);
                setTimeout(updateUIElements, 1000);
            }
        }
            
    }

}();