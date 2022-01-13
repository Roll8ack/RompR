<?php

class tuneinplugin {

	public function __construct() {
		$this->url = 'http://opml.radiotime.com/';
		$this->title = '';
	}

	public function doHeader() {
		// print '<div id="tuneinradio">';
		print uibits::albumHeader(array(
			'id' => 'tuneinlist',
			'Image' => 'newimages/tunein-logo.svg',
			'Searched' => 1,
			'AlbumUri' => null,
			'Year' => null,
			'Artistname' => '',
			'Albumname' => language::gettext('label_tuneinradio'),
			'why' => null,
			'ImgKey' => 'none',
			'class' => 'radio tuneinroot',
			'expand' => true
		));
		print '<div id="tuneinlist" class="dropmenu notfilled is-albumlist"><div class="configtitle"><div class="textcentre expand"><b>'.language::gettext('label_loading').'</b></div></div></div>';
		// print '</div>';
	}

	public function parseParams() {
		if (array_key_exists('url', $_REQUEST)) {
			$this->url = $_REQUEST['url'];
		} else {
			uibits::directoryControlHeader('tuneinlist', language::gettext('label_tuneinradio'));
			print '<div class="containerbox fullwidth dropdown-container"><div class="expand">
				<input class="enter clearbox tuneinsearchbox" name="tuneinsearcher" type="text" ';
			if (array_key_exists('search', $_REQUEST)) {
				print 'value="'.$_REQUEST['search'].'" ';
			}
			print '/></div><button class="fixed tuneinsearchbutton searchbutton iconbutton clickable tunein"></button></div>';
		}
		if (array_key_exists('title', $_REQUEST)) {
			$this->title = $_REQUEST['title'];
			uibits::directoryControlHeader($_REQUEST['target'], htmlspecialchars($this->title));
		}
		if (array_key_exists('search', $_REQUEST)) {
			uibits::directoryControlHeader('tuneinlist', language::gettext('label_tuneinradio'));
			$this->url .= 'Search.ashx?query='.urlencode($_REQUEST['search']);
		}
	}

	public function getUrl() {
		logger::log("TUNEIN", "Getting URL",$this->url);
		$d = new url_downloader(array('url' => $this->url));
		if ($d->get_data_to_string()) {
			$x = simplexml_load_string($d->get_data());
			$v = (string) $x['version'];
			logger::debug("TUNEIN", "OPML version is ".$v);
			$this->parse_tree($x->body, $this->title);
		}
	}

	private function parse_tree($node, $title) {

		foreach ($node->outline as $o) {
			$att = $o->attributes();
			logger::core("TUNEIN", "  Text is",$att['text'],", type is",$att['type']);
			switch ($att['type']) {

				case '':
					print '<div class="configtitle textcentre brick_wide">';
					print '<div class="expand">'.$att['text'].'</div>';
					print '</div>';
					$this->parse_tree($o, $title);
					break;

				case 'link':
					uibits::printRadioDirectory($att, true, 'tunein');
					break;

				case 'audio':
					switch ($att['item']) {
						case 'station':
							$sname = $att['text'];
							$year = 'Radio Station';
							break;

						case 'topic':
							$sname = $title;
							$year = 'Podcast Episode';
							break;

						default:
							$sname = $title;
							$year = ucfirst($att['item']);
							break;

					}

					print uibits::albumHeader(array(
						'id' => 'nodrop',
						'Image' => 'getRemoteImage.php?url='.rawurlencode($att['image']),
						'Searched' => 1,
						'AlbumUri' => null,
						'Year' => $year,
						'Artistname' => ((string) $att['playing'] != (string) $att['subtext']) ? $att['subtext'] : null,
						'Albumname' => $att['text'],
						'why' => 'whynot',
						'ImgKey' => 'none',
						'streamuri' => $att['URL'],
						'streamname' => $sname,
						'streamimg' => 'getRemoteImage.php?url='.rawurlencode($att['image']),
						'class' => 'radiochannel'
					));
					break;

			}
		}

	}

}

if (array_key_exists('populate', $_REQUEST)) {

	chdir('..');

	include ("includes/vars.php");
	include ("includes/functions.php");

	$tunein = new tuneinplugin();
	$tunein->parseParams();
	$tunein->getUrl();

} else {

	$tunein = new tuneinplugin();
	$tunein->doHeader();
}

?>
