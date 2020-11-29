<?php

class baseAlbumImage {
	/*
	Can be initialised in one of several ways

	1. With baseimage (eg albumart/small/thignrirvu.jpg) in order to calculate the paths for the other sizes
		and, optionally, artist, album etc so an image key can be generated
	2. With a key, in which case the artist info etc will be looked up in the collection
	3. With Artist and Album info (see image_info_from_album_info) in order to do an image search or calculate an image key
		For 'artist' of PODCAST, albumpath must be set to the podcasts' base directory (the PODindex).
		A key must NOT be supplied in this case.
	*/

	// Remember to keep albumart_translator in uifunctions.js in step with this

	public function __construct($params) {
		global $prefs;
		foreach (array('artist', 'album', 'key', 'source', 'file', 'base64data', 'mbid', 'albumpath', 'albumuri', 'trackuri') as $param) {
			if (array_key_exists($param, $params) && $params[$param] != '') {
				$this->{$param} = $params[$param];
			} else {
				$this->{$param} = null;
			}
		}
		// We need to be able to send baseimage as '' for it all to work with the collection,
		// And X-AlbumImage defaults to null for all kinds of reasons, probably.
		if (array_key_exists('baseimage', $params)) {
			$this->baseimage = $params['baseimage'];
		} else {
			$this->baseimage = 'No-Image';
		}
		if (array_key_exists("ufile", $_FILES)) {
			$this->file = $_FILES['ufile']['name'];
		}
		if ($this->mbid !== null) {
			if (preg_match('/\d+/', $this->mbid) && !preg_match('/-/', $this->mbid)) {
				logger::debug("ALBUMIMAGE", " Supplied MBID of ".$mbid." looks more like a Discogs ID");
				$this->mbid = null;
			}
		}
		if ($prefs['player_backend'] == 'mopidy') {
			$this->albumpath = urldecode($this->albumpath);
		}
		$this->image_downloaded = false;
		if ($this->baseimage != 'No-Image') {
			$this->images = $this->image_paths_from_base_image($params['baseimage']);
			$this->key = $this->make_image_key();
		} else if ($this->key !== null) {
			logger::log('ALBUMIMAGE', 'Image Infor From Database');
			$this->image_info_from_database();
		} else {
			$this->images = $this->image_info_from_album_info();
		}
	}

	public function get_image_key() {
		return $this->key;
	}

	private function image_exists($image) {
		logger::trace("ALBUMIMAGE", "Checking for existence of file ".$image);
		return file_exists($image);
	}

	public function get_image_if_exists($size = 'small') {
		foreach (array('png', 'svg', 'jpg') as $ext) {
			$this->change_file_extension($ext);
			if ($this->image_exists($this->images[$size])) {
				return $this->images[$size];
			}
		}
		if ($this->artist == 'PLAYLIST' && $this->album == 'Discover Weekly (by spotify)') {
			$this->images['small'] = 'newimages/discoverweekly.jpg';
			$this->images['medium'] = 'newimages/discoverweekly.jpg';
			$this->images['asdownloaded'] = 'newimages/discoverweekly.jpg';
			return 'newimages/discoverweekly.jpg';
		}
		return null;
	}

	public function get_images() {
		return $this->images;
	}

	private function check_if_image_already_downloaded() {
		logger::log('ALBUMIMAGE', 'Checking if image exists for', $this->artist, $this->album);
		$checkimages = $this->image_info_from_album_info();
		if ($this->image_exists($checkimages['small'])) {
			logger::log("ALBUMIMAGE", "  ..  File exists");
			$this->images = $checkimages;
			return true;
		} else {
			return false;
		}
	}

	public function is_collection_image() {
		return preg_match('#albumart/small/#', $this->images['small']);
	}

	public function check_image($domain, $type, $in_playlist = false) {
		global $doing_search;
		// If there's no image, see if we can set a default
		// Note we don't set defaults for streams because coverscaper handles those
		// so it can set them in the playlist even when auto art download is off

		$disc_checked = false;
		if ($this->images['small'] == '' || $this->images['small'] === null) {
			if ($this->artist == 'STREAM') {
				// Stream images may not be in the database
				// BUT they may be present anyway, if a stream was added eg from a playlist
				// of streams and coverscraper found one. So check, otherwise coverscraper
				// will search for it every time the playlist repopulates.
				if ($this->check_if_image_already_downloaded()) {
					return true;
				}
				$disc_checked = true;
			}

			// Checking if the file already exists on disc doesn't help at all
			// when we're building a collection (and only slows things down).
			// If the album is already in the collection it'll have an image (or not) and
			// this will be in the database. The collection update will not change it
			// if this returns no image because we use best_value()

			if ($in_playlist) {
				if (!$disc_checked && $this->check_if_image_already_downloaded()) {
					// Image may have already been downloaded if we've added the album
					// to the Current Playlist from search results.
				} else {
					// Different defaults for the Playlist, we'd like to be able
					// to download images and the information we get in the playlist
					// (from Mopidy anyway, which is where these matter), is more complete
					// than what comes from search results which often lack any sort
					// of useful information.
					switch ($domain) {
						case 'bassdrive':
						case 'internetarchive':
						case 'oe1':
						// case 'soundcloud':
						// case 'youtube':
							$this->images = $this->image_paths_from_base_image('newimages/'.$domain.'-logo.svg');
							break;
					}
				}
				return true;
			}

			if ($doing_search) {
				if (!$disc_checked && $this->check_if_image_already_downloaded()) {
					// We may have searched for it before
					return true;
				}
			}

			switch ($domain) {
				case 'bassdrive':
				case 'dirble':
				case 'internetarchive':
				case 'oe1':
				case 'podcast':
				case 'radio-de':
				// case 'soundcloud':
				case 'tunein':
				// case 'youtube':
					$this->images = $this->image_paths_from_base_image('newimages/'.$domain.'-logo.svg');
					break;

			}
		}
	}

	private function image_paths_from_base_image($image) {
		$images = array(
			'small' => $image,
			'medium' => preg_replace('#albumart/small/#', 'albumart/medium/', $image),
			'asdownloaded' => preg_replace('#albumart/small/#', 'albumart/asdownloaded/', $image)
		);
		if (substr($image, 0, 14) == 'getRemoteImage') {
			array_walk($images, function(&$v, $k) {
				$v .= '&rompr_resize_size='.$k;
			});
		}
		return $images;
	}

	protected function change_file_extension($new) {
		foreach ($this->images as $size => $path) {
			$p = pathinfo($path);
			$this->images[$size] = $p['dirname'].'/'.$p['filename'].'.'.$new;
		}
	}

	private function image_info_from_database() {
		$this->basepath = 'albumart/';
		$info = get_imagesearch_info($this->key);
		foreach ($info as $k => $v) {
			$this->{$k} = $v;
		}
		$smallimage = $this->basepath.'small/'.$this->key.'.jpg';
		$this->images = $this->image_paths_from_base_image($smallimage);
	}

	protected function image_info_from_album_info() {
		switch ($this->artist) {
			case 'PLAYLIST':
				$this->key = $this->make_image_key();
				$this->basepath = 'prefs/plimages/'.$this->key.'/albumart/';
				break;

			case 'STREAM':
				$this->key = $this->make_image_key();
				$this->basepath = 'prefs/userstreams/'.$this->key.'/albumart/';
				break;

			case 'PODCAST':
				$this->key = $this->make_image_key();
				$this->basepath = 'prefs/podcasts/'.$this->albumpath.'/albumart/';
				break;

			default:
				$this->key = $this->make_image_key();
				$this->basepath = 'albumart/';
				break;
		}
		$smallimage = $this->basepath.'small/'.$this->key.'.jpg';
		return $this->image_paths_from_base_image($smallimage);
	}

	private function make_image_key() {
		$key = strtolower($this->artist.$this->album);
		return md5($key);
	}

	public function album_has_no_image() {
		// return true if the image does not exist
		if (substr($this->images['small'], 0, 4) == 'http' || substr($this->images['small'], 0, 14) == 'getRemoteImage') {
			return false;
		}
		if (!$this->images['small']) {
			return true;
		}
		if (!file_exists($this->images['small'])) {
			return true;
		}
		return false;
	}

	public function html_for_image($obj, $imageclass, $size, $lazy = true, $check_exists = false) {
		$extra = (array_key_exists('userplaylist', $obj)) ? 'plimage '.$imageclass : $imageclass;
		if ($check_exists && $this->album_has_no_image()) {
			$this->images['small'] = null;
			$this->images['mediaum'] = null;
			$this->images['asdownloaded'] = null;
		}
		if (!$this->images['small'] && $obj['Searched'] != 1) {
			return '<img class="notexist '.$extra.'" name="'.$obj['ImgKey'].'" src="newimages/transparent.png" />'."\n";
		} else  if (!$this->images['small'] && $obj['Searched'] == 1) {
			return '<img class="notfound '.$extra.'" name="'.$obj['ImgKey'].'" src="newimages/transparent.png"/>'."\n";
		} else {
			if ($lazy) {
				return '<img class="lazy '.$extra.'" name="'.$obj['ImgKey'].'" src="newimages/transparent.png" data-src="'.$this->images[$size].'" />'."\n";
			} else {
				return '<img class="'.$extra.'" name="'.$obj['ImgKey'].'" src="'.$this->images[$size].'" />'."\n";
			}
		}
	}

}

class albumImage extends baseAlbumImage {

	public function set_source($src) {
		$this->source = $src;
	}

	public function has_source() {
		if ($this->source === null && $this->file === null && $this->base64data === null) {
			return false;
		} else {
			return true;
		}
	}

	public function download_image() {
		if (!$this->has_source()) {
			return false;
		}
		$retval = false;
		if ($this->source) {
			$retval = $this->download_remote_file();
		} else if ($this->file) {
			$retval = get_user_file($this->file, $this->key, $_FILES['ufile']['tmp_name']);
		} else if ($this->base64data) {
			$retval = $this->save_base64_data();
		}
		if ($retval !== false) {
			$this->image_downloaded = true;
			$retval = $this->saveImage($retval);
		}
		return $retval;
	}

	public function update_image_database() {
		switch ($this->artist) {
			case 'PLAYLIST';
				break;

			case 'STREAM':
				if ($this->image_downloaded) {
					update_stream_image($this->album, $this->images['small']);
				}
				break;

			case 'PODCAST':
				if ($this->image_downloaded) {
					update_podcast_image($this->albumpath, $this->images['small']);
				}
				break;

			default:
				update_image_db($this->key, $this->image_downloaded, $this->images['small']);
				break;

		}
	}

	public function set_default() {
		if ($this->artist == "STREAM") {
			// Set a default image for streams when we are doing an album art download
			// otherwise they get searched for on every refresh of the playlist if they're
			// not in the database and nothing gets found the first time.
			$this->source = 'newimages/broadcast.svg';
			return $this->download_image();
		}
		return false;
	}

	public function get_artist_for_search() {
		switch ($this->artist) {
			case 'PLAYLIST':
			case 'STREAM':
				return '';
				break;

			case 'PODCAST':
			case 'Podcasts':
				return 'Podcast';
				break;

			default:
				return $this->artist;
				break;
		}
	}

	public function change_name($new_name) {
		switch ($this->artist) {
			case 'PLAYLIST':
				logger::log("FUCKINGHELL", "Playlist name changing from ".$this->album." to ".$new_name);
				if (file_exists($this->images['small'])) {
					$oldbasepath = dirname($this->basepath);
					$oldkey = $this->key;
					$this->album = $new_name;
					$this->images = $this->image_info_from_album_info();
					$newbasepath = dirname($this->basepath);
					logger::log("ALBUMIMAGE", "Renaming Playlist Image from ".$oldbasepath." to ".$newbasepath);
					rename($oldbasepath, $newbasepath);
					foreach ($this->images as $image) {
						$oldimage = dirname($image).'/'.$oldkey.'.jpg';
						rename($oldimage, $image);
					}
				}
				break;
		}
	}

	private function saveImage($download_file) {
		$imagehandler = new imageHandler($download_file);
		switch ($imagehandler->checkImage()) {
			// Include the MIME types here in case we're using ImageMagick after an error
			// was thrown in gdImage
			case IMAGETYPE_PNG:
			case 'image/png':
				$this->change_file_extension('png');
				break;

			case IMAGETYPE_SVG:
			case 'image/svg':
			case 'image/svg+xml':
				$this->change_file_extension('svg');
				break;
		}
		foreach ($this->images as $image) {
			$dir = dirname($image);
			$size = basename($dir);
			if (file_exists($image)) {
				unlink($image);
			}
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
			logger::log("ALBUMIMAGE", "  Creating file ".$image);
			switch ($size) {
				case 'small':
					$imagehandler->resizeToWidth(IMAGESIZE_SMALL);
					$imagehandler->save($image, IMAGEQUALITY_SMALL);
					break;

				case 'smallish':
					$imagehandler->resizeToWidth(IMAGESIZE_SMALLISH);
					$imagehandler->save($image, IMAGEQUALITY_SMALLISH);
					break;

				case 'medium':
					$imagehandler->resizeToWidth(IMAGESIZE_MEDIUM);
					$imagehandler->save($image, IMAGEQUALITY_MEDIUM);
					break;

				case 'asdownloaded':
					$imagehandler->reset();
					$imagehandler->save($image, IMAGEQUALITY_ASDOWNLOADED);
					break;
			}
		}
		unlink($download_file);
		$imagehandler->destroy();
		return $this->images;

	}

	private function download_remote_file() {
		$download_file = 'prefs/temp/'.$this->key;
		$retval = $download_file;
		if (preg_match('/^https*:/', $this->source) || preg_match('/^getRemoteImage.php/', $this->source)) {
			$d = new url_downloader(array('url' => $this->source));
			if ($d->get_data_to_file($download_file, true)) {
				$content_type = $d->get_content_type();
				if (substr($content_type,0,5) != 'image' && $content_type != 'application/octet-stream') {
					logger::warn("ALBUMIMAGE", "  .. Content type is ".$content_type." - not an image file! ".$this->source);
					$retval = false;
				}
			} else {
				$retval = false;
			}
		} else {
			logger::log("ALBUMIMAGE", "  .. Copying apparent local file",$this->source,'to',$download_file);
			if (!copy(rawurldecode($this->source), $download_file)) {
				logger::warn("ALBUMIMAGE", "    .. File Copy Failed");
				$retval = false;
			}
		}
		return $retval;
	}

	private function save_base64_data() {
		logger::log("ALBUMIMAGE", "  Saving base64 data");
		$download_file = 'prefs/temp/'.$this->key;
		create_image_from_base64($this->base64data, $download_file);
		return $download_file;
	}

}

function create_image_from_base64($base64, $download_file) {
	$image = explode('base64,',$base64);
	file_put_contents($download_file, base64_decode($image[1]));
}

function artist_for_image($type, $artist) {
	switch ($type) {
		case 'stream':
			$artistforimage = 'STREAM';
			break;

		default:
			$artistforimage = $artist;
			break;
	}
	return $artistforimage;
}

class imageHandler {

	private $filename;
	private $image;

	public function __construct($filename) {
		if (extension_loaded('gd')) {
			$this->image = new gdImage($filename);
			if ($this->image->checkImage() === false) {
				// GD does not support SVG or ICO files, plus different installations
				// have different built-in support. Hence we fall back to
				// imagemagick if gd doesn't support it. (GD is faster, so we prefer that)
				logger::info('IMAGEHANDLER', 'Switching to ImageMagick');
				$this->image = new imageMagickImage($filename);
			}
		} else {
			$this->image = new imageMagickImage($filename);
		}
	}

	public function checkImage() {
		return $this->image->checkImage();
	}

	public function reset() {
		$this->image->reset();
	}

	public function save($filename, $compression = 75) {
		$this->image->save($filename, $compression);
	}

	public function outputResizedFile($size) {
		return $this->image->outputResizedFile($size);
	}

	public function resizeToWidth($width) {
		logger::trace('IMAGEHANDLER', 'Resizing to width', $width);
		$this->image->resizeToWidth($width);
	}

	public function get_image_dimensions() {
		return $this->image->get_image_dimensions();
	}

	public function destroy() {
		$this->image->destroy();
	}

}

class imageMagickImage {

	// imageMagickImage will be used when:
	// GD is installed but does not support the format of the image (GD never supports SVG or ICO)
	// OR GD is not installed

	// If Imagemagick is not installed either, then it defaults to just copying files which, for outputResizedImage,
	// will only work for PNG and JPEG images, and no resizing will occur

	private $filename;
	private $convert_path;
	private $resize_to = 0;
	private $image_type;
	private $cmdline_file;

	public function __construct($filename) {
		$this->filename = $filename;
		$this->convert_path = find_executable('convert');
		$this->image_type = mime_content_type($this->filename);
		logger::debug('IMAGEMAGICK', 'Construct Image Type is "'.$this->image_type.'"');
		if ($this->image_type == 'text/plain') {
			$this->image_type = 'image-svg+xml';
		}
		if ($this->image_type == 'image/svg') {
			// MUST use image/svg+xml for MIME type for output, but some versions of PHP
			// mime_content_type return image/svg
			$this->image_type = 'image-svg+xml';
		}
		if ($this->image_type == 'image/x-icon') {
			// Imagemagick can't auto-detect .ico files that don't have the .ico extension
			// and we don't use file extensions in the cache. This forces IM to decode it as a .ico
			$this->cmdline_file = 'ico:'.$filename;
		} else {
			$this->cmdline_file = $filename;
		}
	}

	public function reset() {
		$this->resize_to = 0;
	}

	public function checkImage() {
		logger::debug("IMAGEMAGICK", "  Check Image type is ".$this->image_type);
		$c = $this->convert_path."identify \"".$this->cmdline_file."\" 2>&1";
		$o = array();
		$r = exec($c, $o);
		if (preg_match('/no decode delegate/', $r)) {
			logger::warn('IMAGEMAGICK', 'Unsupported image');
			return false;
		}
		return $this->image_type;
	}

	public function save($filename, $compression) {
		if ($this->image_type == 'image/svg+xml') {
			logger::log("IMAGEMAGICK", "  Copying SVG file instead of converting");
			$this->justCopy($filename);
		} else if ($this->convert_path === false) {
			logger::warn("IMAGEMAGICK", "WARNING! ImageMagick not installed");
			$this->justCopy($filename);
		} else {
			if ($this->image_type == "image/png") {
				$params = ' -quality 95';
			} else {
				$params = ' -quality '.$compression.' -alpha remove';
			}
			if ($this->resize_to > 0) {
				$params .= ' -resize '.$this->resize_to;
			}
			$cmd = 'convert "'.$this->cmdline_file.'"'.$params.' "'.$filename.'"';
			logger::debug("IMAGEMAGICK", "  Command is ".$cmd);
			if (substr($filename, -1) == '-') {
				// Output is to STDOUT
				passthru($this->convert_path.$cmd, $ret);
			} else {
				$cmd .= ' 2>&1';
				$r = exec($this->convert_path.$cmd, $o, $ret);
				logger::debug("IMAGEMAGICK", "    Final line of output was ".$r);
			}
			logger::debug("IMAGEMAGICK", "    Return Value was ".$ret);
			// No point trying a copy file fallback, as if ImageMagick can't handle it it's shite.
		}
	}

	private function justCopy($filename) {
		if (substr($filename, -1) == '-') {
			readfile($this->filename);
		} else {
			copy($this->filename, $filename);
		}
	}

	public function outputResizedFile($size) {
		// Set content type and output to STDOUT
		switch ($this->image_type) {
			case 'image/svg+xml':
				$content_type = 'image/svg+xml';
				// NOTE SVG is not a valid type for ImageMagick output, this is just a placeholder
				// with a '-' on the end to denote output to STDOUT. We always just copy SVG files.
				$outputfile = 'SVG:-';
				break;
			case 'image/png':
				$content_type = 'image/png';
				$outputfile = 'PNG:-';
				break;
			default:
				$content_type = 'image/jpeg';
				$outputfile = 'JPEG:-';
				break;
		}
		logger::log('IMAGEMAGICK', 'Outputting',$this->filename,'as',$content_type,'size',$size);
		header('Content-type: '.$content_type);
		switch ($size) {
			case 'small':
				$this->resizeToWidth(IMAGESIZE_SMALL);
				return $this->save($outputfile, IMAGEQUALITY_SMALL);
				break;

			case 'smallish':
				$this->resizeToWidth(IMAGESIZE_SMALLISH);
				return $this->save($outputfile, IMAGEQUALITY_SMALLISH);
				break;

			case 'medium':
				$this->resizeToWidth(IMAGESIZE_MEDIUM);
				return $this->save($outputfile, IMAGEQUALITY_MEDIUM);
				break;

			default:
				return $this->save($outputfile, IMAGEQUALITY_ASDOWNLOADED);
				break;

		}
	}

	public function resizeToWidth($width) {
		$s = $this->get_image_dimensions();
		if ($width > $s['width']) {
			logger::debug('IMAGEMAGICK', 'Not resizing as requested size is larger than image');
		} else {
			$this->resize_to = $width;
		}
	}

	public function get_image_dimensions() {
		$width = -1;
		$height = -1;
		if ($this->image_type != 'image/svg+xml' && $this->convert_path !== false) {
			$c = $this->convert_path."identify \"".$this->cmdline_file."\" 2>&1";
			$o = array();
			$r = exec($c, $o);
			if (preg_match('/ (\d+)x(\d+) /', $r, $matches)) {
				$width = $matches[1];
				$height = $matches[2];
			}
		}
		logger::trace('IMAGEMAGICK', 'Dimensions are',$width,'x',$height);
		return array('width' => $width, 'height' => $height);
	}

	public function destroy() {

	}

}

class gdImage {

	private $image;
	private $resizedimage;
	private $image_type = false;
	private $filename;

	public static function gd_handle_error($errno, $errstr, $errfile, $errline) {
		logger::warn("GD_IMAGE", "Error",$errno,$errstr,"in",$errfile,"at line",$errline);
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		return true;
	}

	public function __construct($filename) {

		set_error_handler('gdImage::gd_handle_error', E_ALL);

		$this->filename = $filename;
		logger::log("GD-IMAGE", "Checking File ".$filename);
		$imgtypes = imagetypes();
		try {
			$image_info = getimagesize($filename);
			$image_type = $image_info[2];
			logger::debug("GD-IMAGE", "Image Type is ".$image_type);
		} catch (Exception $e) {
			logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
			$image_type = false;
		}

		// We're being very careful here to check that the image is of a supported type
		// without throwing any errors. Belt and braces, since different PHP-GD installations
		// have different supported types, and IMG_BMP wasn't introduced until PHP7.2
		// The case values for the switch statement are always defined to something, since vars.php sets them
		// to the MIME type of that image if they aren't already defined, as that's what imageMagickImage uses
		// for its image_type

		// So if GD is loaded but doesn't support a particular image this sets image_type to false, and imageHandler
		// falls back to imagemagick.

		// This list contains all the image types that GD might be able to read, currently.

		// In the outside chance that an error occurs on a supported image type - sometimes libpng throws a fatal wobbler on some images -
		// the error handler catches it and we fall back to Imagemagick

		switch ($image_type) {
			case IMAGETYPE_JPEG:
				logger::trace("GD-IMAGE", "Image type is JPEG");
				if (defined('IMG_JPG') && ($imgtypes && IMG_JPG) && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
					try {
						$this->image = imagecreatefromjpeg($filename);
					} catch (Exception $e) {
						logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_GIF:
				logger::trace("GD-IMAGE", "Image type is GIF");
				if (defined('IMG_GIF') && ($imgtypes && IMG_GIF) && function_exists('imagecreatefromgif')) {
					try {
						$this->image = imagecreatefromgif($filename);
					} catch (Exception $e) {
						logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_PNG:
				logger::trace("GD-IMAGE", "Image type is PNG");
				if (defined('IMG_PNG') && ($imgtypes && IMG_PNG) && function_exists('imagecreatefrompng') && function_exists('imagepng')) {
					try {
						$this->image = imagecreatefrompng($filename);
					} catch (Exception $e) {
						logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_WBMP:
				logger::trace("GD-IMAGE", "Image type is WBMP");
				if (defined('IMG_WBMP') && ($imgtypes && IMG_WBMP) && function_exists('imagecreatefromwbmp')) {
					try {
						$this->image = imagecreatefromwbmp($filename);
					} catch (Exception $e) {
						logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_XBM:
				logger::trace("GD-IMAGE", "Image type is XBM");
				if (defined('IMG_XPM') && ($imgtypes && IMG_XPM) && function_exists('imagecreatefromxbm')) {
					try {
						$this->image = imagecreatefromxbm($filename);
					} catch (Exception $e) {
						logger::warn("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_WEBP:
				logger::trace("GD-IMAGE", "Image type is WEBP");
				if (defined('IMG_WEBP') && ($imgtypes && IMG_WEBP) && function_exists('imagecreatefromwebp')) {
					try {
						$this->image = imagecreatefromwebp($filename);
					} catch (Exception $e) {
						logger::log("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			case IMAGETYPE_BMP:
				logger::trace("GD-IMAGE", "Image type is BMP");
				if (defined('IMG_BMP') && ($imgtypes && IMG_BMP) && function_exists('imagecreatefrombmp')) {
					try {
						$this->image = imagecreatefrombmp($filename);
					} catch (Exception $e) {
						logger::log("GD-IMAGE", "  GD threw an error when handling",$filename);
						$image_type = false;
					}
				} else {
					$image_type = false;
				}
				break;

			default:
				$image_type = false;
				$this->image_type = false;
				break;
		}

		if ($image_type !== false) {
			$this->image_type = $image_type;
			$this->reset();
		}

	}

	public function checkImage() {
		if ($this->image_type === false) {
			logger::warn("GD-IMAGE", "  Unsupported Image Type");
		}
		return $this->image_type;
	}

	public function reset() {
		$this->resizedimage = $this->image;
		imagealphablending($this->resizedimage, false);
		imagesavealpha($this->resizedimage, true);
	}

	public function save($filename, $compression) {
		// If $filename is null this outputs to STDOUT
		if ($this->image_type == IMAGETYPE_PNG) {
			// Be aware - We always save PNGs as PNGs to preserve alpha channel
			imagepng($this->resizedimage, $filename, 9);
		} else {
			imagejpeg($this->resizedimage, $filename, $compression);
		}
	}

	public function outputResizedFile($size) {
		if ($this->image_type == IMAGETYPE_PNG) {
			logger::trace("GD-IMAGE", "  Outputting PNG file of size",$size);
			header('Content-type: image/png');
		} else {
			logger::trace("GD-IMAGE", "  Outputting JPEG file of size",$size);
			header('Content-type: image/jpeg');
		}
		switch ($size) {
			case 'small':
				$this->resizeToWidth(IMAGESIZE_SMALL);
				$this->save(null, IMAGEQUALITY_SMALL);
				break;

			case 'smallish':
				$this->resizeToWidth(IMAGESIZE_SMALLISH);
				$this->save(null, IMAGEQUALITY_SMALLISH);
				break;

			case 'medium':
				$this->resizeToWidth(IMAGESIZE_MEDIUM);
				$this->save(null, IMAGEQUALITY_MEDIUM);
				break;

			default:
				$this->save(null, IMAGEQUALITY_ASDOWNLOADED);
				break;
		}
		return true;
	}

	public function resizeToWidth($width) {
		$ratio = $width / $this->getWidth();
		if ($ratio > 1) {
			logger::debug('GD-IMAGE', "Not resizing as requested size is larger than image. Asked for",$width,"but image is",$this->getWidth());
			$this->reset();
		} else {
			$height = $this->getheight() * $ratio;
			$this->resize($width,$height);
		}
	}

	private function getWidth() {
		return imagesx($this->image);
	}

	private function getHeight() {
		return imagesy($this->image);
	}

	private function resize($width, $height) {
		logger::debug('GD-IMAGE', 'Resizing to',$width,'x',$height);
		$this->resizedimage = imagecreatetruecolor($width, $height);
		imagealphablending($this->resizedimage, false);
		imagesavealpha($this->resizedimage, true);
		imagecopyresampled($this->resizedimage, $this->image, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
	}

	public function get_image_dimensions() {
		return array('width' => $this->getWidth(), 'height' => $this->getHeight());
	}

	public function destroy() {
		imagedestroy($this->image);
	}

}

?>
