<?php

class language {

	private static $translations = array();

	private static function load_translations() {
		logger::log('LANGUAGE', 'Loading Translations');
		// Always load English, it provides defaults for anything missing in the translation
		require_once('international/en-GB.php');
		$interface_language = self::get_interface_language();
		if (file_exists('international/'.$interface_language.'.php')) {
			require_once('international/'.$interface_language.'.php');
		} else {
			logger::log("INTERNATIONAL", "Translation ".$interface_language." does not exist. Defaulting to English");
			$prefs['interface_language'] = "en-GB";
			savePrefs();
		}
	}

	public static function add_translations($t) {
		self::$translations = array_merge(self::$translations, $t);
	}

	public static function gettext($key, $sub = null) {
		if (count(self::$translations) == 0) {
			self::load_translations();
		}
		if (array_key_exists($key, self::$translations)) {
			if (is_array($sub)) {
				return htmlspecialchars(vsprintf(self::$translations[$key], $sub), ENT_QUOTES);
			} else {
				return htmlspecialchars(self::$translations[$key], ENT_QUOTES);
			}
		} else {
			logger::error("INTERNATIONAL", "ERROR! Translation key ".$key." not found!");
			return "UNKNOWN TRANSLATION ".$key;
		}

	}

	public static function get_interface_language() {
		// The language used for the interface is prefs.interface_language and is initially undefined
		// and we set it according to what the browser tells us.
		global $prefs;
		return array_key_exists("interface_language", $prefs) ? $prefs["interface_language"] : self::get_browser_locale();
	}

	public static function get_browser_locale() {
		if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
			$lngs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
			return $lngs[0];
		} else {
			return 'en-GB';
		}
	}

	public static function get_browser_language() {
		// Return the two-letter language code from the browser (eg en, fr)
		// This is the equivalnt of the ISO639 alpha-2 code required by last.fm and wikipedia
		// when asking for results in a specific language, and is saved as the pref lastfmlang.
		// (Note, lastfmlang can also be 'default' or 'browser' or 'interface')
		if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
			return substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
		} else {
			return 'en';
		}
	}

	public static function get_browser_country() {
		// return the two-letter country code from the browser (eg GB, FR)
		// This is the equivalent of the ISO3166-1 alpha-2 code required by Spotify
		// fro making sure search results are appropriate for the user's market/country
		// It is saved in the pref lastfm_country_code
		if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
			return substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 3, 2);
		} else {
			return 'GB';
		}
	}

	public static function get_all_translations() {
		if (count(self::$translations) == 0) {
			self::load_translations();
		}
		return self::$translations;
	}

	public static function get_language_list() {
		$retval = array();
		$langs = glob('international/*.php');
		$translate_language = substr(self::get_interface_language(), 0, 2);
		foreach ($langs as $lang) {
			$locale = pathinfo($lang, PATHINFO_FILENAME);
			if (class_exists('Locale')) {
				$name = Locale::getDisplayName($locale, $translate_language);
			} else {
				$name = $locale;
			}
			$retval[$locale] = $name;
		}
		return $retval;
	}

}
?>