<?php

namespace App;

final class Scrapper
{

    private $communication_channel;
    private $pages_channel;

    static $instance;


    /*
     * PUBLIC MEMBERS
    */

    public static function getInstance()
    {
        if ( !isset( self::$instance ) )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }


    public function do_parse()
    {
        $html = curl_exec( $this->communication_channel );
        
        $xpath = $this->get_xpath($html);

        $node = $xpath->query('//a[contains(@class, "goto-last-page")]')->item(0);
        $pages_amount = $node->getAttribute('data-gotopage');
        $href_template = explode('&', $node->getAttribute('href') )[0];

        for ($page = 0; $page <= $pages_amount; $page++)
        {
            $this->parse_page( $href_template, $page );
        }
    }
    
    
    /*
     * PRIVATE MEMBERS
    */

    private function __construct()
    {
        $this->communication_channel = curl_init( \App\Config::$results_url );
        $this->init_channel();
    }


    private function init_channel()
    {   
        $token = $this->retrieve_csrf();

        $communication_options = [
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.182 Safari/537.36',
                'Referer: https://search.ipaustralia.gov.au/trademarks/search/advanced',
                "Cookie: XSRF-TOKEN=$token;"
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTFIELDS => "_csrf=$token&wv%5B0%5D=" . \App\Config::$keyword_param . "&wt%5B0%5D=PART&weOp%5B0%5D=AND&wv%5B1%5D=&wt%5B1%5D=PART&wrOp=AND&wv%5B2%5D=&wt%5B2%5D=PART&weOp%5B1%5D=AND&wv%5B3%5D=&wt%5B3%5D=PART&iv%5B0%5D=&it%5B0%5D=PART&ieOp%5B0%5D=AND&iv%5B1%5D=&it%5B1%5D=PART&irOp=AND&iv%5B2%5D=&it%5B2%5D=PART&ieOp%5B1%5D=AND&iv%5B3%5D=&it%5B3%5D=PART&wp=&_sw=on&classList=&ct=A&status=&dateType=LODGEMENT_DATE&fromDate=&toDate=&ia=&gsd=&endo=&nameField%5B0%5D=OWNER&name%5B0%5D=&attorney=&oAcn=&idList=&ir=&publicationFromDate=&publicationToDate=&i=&c=&originalSegment="
        ];

        curl_setopt_array($this->communication_channel, $communication_options);
    }


    private function retrieve_csrf()
    {
        $target_headers = get_headers( \App\Config::$search_url );
        
        $needle = array_filter( $target_headers, function ( $header ) {
            return strpos( $header, 'XSRF-TOKEN') !== false;
        });
        
        $token_str = explode(' ', array_shift($needle))[1];
        $token_str = str_replace(';', '', $token_str);
        return explode('=', $token_str)[1];
    }


    private function parse_page( $href, $page )
    {
        $this->pages_channel = curl_init( \App\Config::$document_root_url . $href . "&=$page" ); 
        curl_setopt($this->pages_channel, CURLOPT_RETURNTRANSFER, true);

        $html = curl_exec($this->pages_channel);
        $xpath = $this->get_xpath( $html );

        foreach ( $xpath->query('//tr[contains(@class, "mark-line") and contains(@class, "result")]') as $result )
        {
            $parent_id = $result->parentNode->getAttribute('id');
            $json = [
                'number' => $xpath->query('//tbody[@id="' . $parent_id . '"]/tr[contains(@class, "mark-line") and contains(@class, "result")]/td[@class="number"]')->item(0)->nodeValue,
                'logo_url' => $xpath->query('//tr[contains(@class, "mark-line") and contains(@class, "result")]/td[contains(@class, "image")]/img')->item(0)->getAttribute('src'),
                'name' => $xpath->query('//tbody[@id="' . $parent_id .  '"]/tr[contains(@class, "mark-line")]/td[contains(@class, "trademark") and contains(@class, "words")]')->item(0)->nodeValue,
                'classes' => $xpath->query('//tbody[@id="' . $parent_id . '"]/tr[contains(@class, "mark-line") and contains(@class, "result")]/td[@class="classes "]')->item(0)->nodeValue,
                'details_page_url' => $result->getAttribute('data-markurl')
            ];

            $status = $xpath->query('//tr[contains(@class, "mark-line") and contains(@class, "result")]/td[@class="status"]')->item(2)->nodeValue;
            if ( strpos($status, ': ') !== false )
            {
                $statuses = explode(': ', $status); 
                $json['status1'] = $statuses[0];
                $json['status2'] = $statuses[1];
            }
            else
            {
                $json['status'] = $status;
            }

            echo json_encode( $json ) . "\n\n";
        }
    }


    private function get_xpath( $html )
    {
        $dom = new \DOMDocument();

        libxml_use_internal_errors(true); // supress some warnings ^_^
        $dom->loadHTML($html);
        libxml_use_internal_errors(false); // and enable them again

        return new \DOMXpath( $dom );
    }
}