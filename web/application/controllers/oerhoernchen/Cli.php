<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'third_party/simple_dom_parser/simple_dom_parser.php';

class Cli extends CI_Controller {

	public function __construct() {
		parent::__construct();

		$logged_in = $this->ion_auth->logged_in();

		// protect controller
		if (!$logged_in)
		{
			 redirect('auth/login');
		}

		if ($logged_in) {
			$this->user_id = $this->ion_auth->user()->row()->id;
		} else {
			$this->user_id = null;
		}
		log_message('debug', 'USERID: ' . $this->user_id);

		// add data to views
		$data['logged_in'] = $logged_in;
		$this->load->vars($data);

		/* pro login */
		/*$this->load->model("user_model");
		if (!$this->user->loggedin) {
			redirect(site_url("login"));
		}*/

    // 2DO: put this in helper!
		// ID = 2 -> editorialstaff
		/*if (!$this->user_model->check_user_in_group($this->user->info->ID, 2)) {
			// 2DO: is show_error the best solution?
			show_error("You are not in the User Group Friends so you cannot view this page!");
		}*/

	}

    // usage MAMP php --> ...
    // docs:
    // 2DO: how to add param via CLI?
    public function import_scrapy_json_results(){

			$this->load->library('oerhoernchen/appbase');

			// 2DO: GET FILE NAME VIA post
			// 2DO: SANITIZE, NO BULLSHIT?
			$jsonFileString = file_get_contents("../scrapy/scraped_items.json");

			$results = json_decode($jsonFileString);
			custom_log_message("Last json error, right now there is an extra comma sometimes (error code 4): ".json_last_error());
			//var_dump($results);
			foreach($results as $item){
				// 2DO: if data source === HOOU -> parse hoou
				// 2DO: if data source === ZOERR -> normal parse function
				custom_log_message('Parse item: '.$item->page_url.' - '.$item->filename);

				$htmlResponse = file_get_contents("../scrapy/html_exports/".$item->filename);

				// 2DO: parse normal html, check if license is overriden from projects.json?

				if($item->project_key=='hoou'){

					custom_log_message('Start parsing for HOOU - '.$item->filename);

					$htmlResponse = file_get_contents("../scrapy/html_exports/".$item->filename);

					$resultData = $this->parse_hoou($htmlResponse);
					var_dump($resultData);
					// 2DO: check for errors
					custom_log_message("Result data: ".print_r($resultData,true));
					$index = "highereducation";

					$sanitizedObjectData = $resultData;
					//append url
					$resultData->main_url = $item->page_url;
					$resultElasticId = $this->appbase->publish_to_index_and_log_in_database($index, $sanitizedObjectData);

					// 2DO: check $resultElasticId for successful
					custom_log_message("Result id: ".$resultElasticId);

				}
				else{
					$this->parse($item->content);
				}

			}


    }

    // http://localhost/2019-oerhoernchen20-prologin/oerhoernchen/cli/parse_hoou/
    public function parse_hoou($htmlContent){
      //$htmlContent = file_get_contents("application/data/hoou_example.html");
      $this->load->library('oerhoernchen/htmlanalyzer');
      $this->htmlanalyzer->analyze_html_hoou($htmlContent);
      //var_dump($this->htmlanalyzer->get_metadata());
			return $this->htmlanalyzer->get_metadata();
    }

		// BETTER CALL THIS FROM CLI
		// cd /Users/admin/webserver/2019-oerhoernchen20/web/
		// /Applications/MAMP/bin/php/php7.2.1/bin/php index.php oerhoernchen/cli crawl hoou
		// /Applications/MAMP/bin/php/php7.2.1/bin/php index.php oerhoernchen/cli crawl zoerr
		public function crawl($projectkey){

			switch($projectkey){
				case 'zoerr':
					$sitemapUrl="https://www.oerbw.de/edu-sharing/eduservlet/sitemap?from=0";
					$urlStrPosValue="/render/";
					break;
				case 'hoou':
					$sitemapUrl="https://www.hoou.de/sitemap.xml";
					$urlStrPosValue="hoou.de/materials/";
					break;
				default:
					show_error("No project key provided");
					break;
			}

			$this->load->library('oerhoernchen/htmlanalyzer');
			$this->load->library('oerhoernchen/appbase');

			custom_log_message("Start crawling");
			// 2DO: Get sitemap file via cURL
			$sitemapxml = file_get_contents($sitemapUrl); // 2DO: error handling
			$sitemapObject = simplexml_load_string($sitemapxml);
			//var_dump($sitemapObject);
			// get all links containing "materials/"
			$urls = [];
			foreach($sitemapObject as $entry){
				if(strpos($entry->loc,$urlStrPosValue) !== FALSE){
					$urls[] = (string) $entry->loc;
				}
			}

			custom_log_message("Found ".count($urls)." URLS with /materials/");


			// 2DO: curl all urls

			foreach($urls as $url){
				custom_log_message("Curling url: ".$url);

				$ch = curl_init();
				$timeout = 5;
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
				curl_setopt($ch, CURLOPT_URL, $url);

				// Get URL content
				$responseHtml = curl_exec($ch);
				// close handle to release resources
				curl_close($ch);
				custom_log_message("Sleep 2 seconds to be gentle to the server ... ;-)");
				sleep(2);
				// 2DO: catch curl errors and show it (see ajax_add_entry in community_bookmarks)

				switch($projectkey){
					case 'hoou':
						$this->htmlanalyzer->analyze_html_hoou($responseHtml);
						break;
						case 'zoerr':
						$this->htmlanalyzer->analyze_html($responseHtml);
						break;
					}

	      //var_dump($this->htmlanalyzer->get_metadata());
				$sanitizedObjectData = $this->htmlanalyzer->get_metadata();
				$sanitizedObjectData->main_url = $url;
				// 2DO: Introduce field to buy just one appbase index?
				$sanitizedObjectData->oerhoernchen_index = "highereducation";
				$sanitizedObjectData->projectkey = filter_var($projectkey, FILTER_SANITIZE_STRING);
				custom_log_message("Sanitized object data: ".print_r($sanitizedObjectData,true));
				custom_log_message("Publish it to index ...");
				$resultElasticId = $this->appbase->publish_to_index_and_log_in_database("highereducation", $sanitizedObjectData);
				custom_log_message("Elastic success id: ".$resultElasticId);
			}


			// 2DO: parse data with HtmlAnalyzer

			// done. :)
		}

}
