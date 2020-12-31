# Using RompЯ with mobile devices

## Playcounts and Scrobbling

RompЯ works fine on almost all mobile devices but, because it runs in a web browser, if the device goes to sleep (the screen switches off) then RompЯ will not be able to update Playcounts or scrobble tracks to Last.FM. This page explains how to set up some extras so that this still works.

One option is to always leave a desktop browser open on RompЯ and that will take care of everything, but that won't be an option for most people, and the following is neater anyway.

## Romonitor - Updating RompЯ's Playcounts and Scrobbling to Last.FM

Even if you don't care about Playcounts, they are used by many of the [Personalised Radio Stations](/RompR/Personalised-Radio), so it's still useful.

RompЯ is provided with a small program called romonitor that takes care of updating playcounts, marks podcasts as listened, scrobbles tracks to Last.FM,
and keeps personalised radio stations running even when no browser is open. It just needs a little setting up.

## Running RoMonitor:

Just create a file /lib/systemd/system/romonitor.service that looks like this

    [Unit]
    Description=RompR Playback Monitor
    After=avahi-daemon.service
    After=dbus.service
    After=network.target
    After=nss-lookup.target
    After=remote-fs.target
    After=sound.target
    After=mariadb.service
    After=nginx.service

    [Service]
    User=www-data
    PermissionsStartOnly=true
    WorkingDirectory=/PATH/TO_ROMPR
    ExecStart=/usr/bin/php /PATH/TO/ROMPR/romonitor.php  --currenthost Kitchen --player_backend mopidy

    [Install]
    WantedBy=multi-user.target

You need to make some changes to that:

* **/PATH/TO/ROMPR** is the full path to your RompЯ installation. Refer to the installation instructions for more details.
* **currenthost** should be followed by the name of one of the Players as displayed in your Configuration menu.
* **player_backend** should be followed by either mpd or mopidy, depending on the type of player.
* **User=** must be set to the username your web server runs as. On Debian/Ubuntu systems this is www-data

Then enable it with

    sudo systemctl enable romonitor.service

And start it with

    sudo systemctl start romonitor

### If you're using Multiple Players

In the case where you're using [multiple players](/RompR/Using-Multiple-Players) you'll need to create a separate service for each player.


## Scrobbling

You can use [mopidy-scrobbler](https://github.com/mopidy/mopidy-scrobbler) for Mopidy or [mpdscribble](https://www.musicpd.org/clients/mpdscribble/) for mpd to scrobble, but if you do then your scrobbles might not match exactly what's in your collection - especially if you use podcasts. If you use romonitor to scrobble instead, then everything will be consistent.

To make romonitor scrobble to Last.FM you must first [log in to Last.FM](/RompR/LastFM) from the main Rompr application, then start romonitor with an additional paramter, for example

    ExecStart=/usr/bin/php /PATH/TO/ROMPR/romonitor.php  --currenthost Kitchen --player_backend mopidy --scrobbling true

Also make sure you're not scrobbling from the main RompR application or mpdscribble/mopidy-scrobbler etc or all your plays will be scrobbled twice!

### Troubleshooting

If it's not working, first enable [debug logging](/RompR/Troubleshooting) to at least level 8 then restart romonitor. You'll see some output from it in the web server's error log (and your custom logifle if you're using one).

### Personalised Radio Stations

The other thing that requires the device to be awake is populating Personalised Radio stations. romonitor can take over this work for *some* of the Personalised Radio stations, meaning you can start one of these playing from a browser and romonitor will keep it running if you close the browser.

Currently the list of Personalised Radio stations supported by romonitor is

* All Ratings Radios
* Tags Radio
* Genre Radio
* Artist Radio
* All Tracks At Random
* Never Played Tracks
* Recently Played Tracks
* Favourite Tracks
* Favourite Albums
* Recently Added Tracks
* Recently Added Albums
* All Custom Radio Stations
