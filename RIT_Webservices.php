<?php
/**
 * Class encompassing basic operations on touristic data objects made possible by RIT webservices
 *
 * There are three ways to make use of this class, each way utilizing more its methods and requiring less manual work but also requiring
 * better understanding of how this class works:
 *
 * Level 1) Use it only to get properly set-up SoapClient and then proceed with manual request building and response decoding;
 *
 * Level 2) Use request creating functions to create proper request objects and call webservices - but no response decoding;
 *
 * Level 3) Request creating with some helper functions to decode and process recieved response.
 *
 * At level 1 you need to know only about {@see __construct()} and {@see get_webservice()}. The latter will give you SoapClient object.
 *
 * At level 2 you can start with reading documentation of {@see add_object()} and tracing its helper functions to encode object identifier.
 * Then you can discover large family of wrappers for {@see get_objects()}. Then there are:
 * very important {@see get_metadata()},
 * useful {@see get_events(), @see get_object_languages() }
 * and some more functions are still waiting to be implemented.
 *
 * At level 3 you have everything from level 2 but some response decoding functions (at this time primarily focused on metadata) appear:
 * {@see get_languages()},
 * {@see get_dictionary()},
 * {@see get_dictionaries()},
 * {@see get_dictionary_title()},
 * {@see get_dictionary_values()},
 * {@see get_category()},
 * {@see get_categories()},
 * {@see get_childless_categories()}.
 * More are underway.
 *
 * If it wasn't enough, you can always subclass RIT_Webservices and access some protected methods. There isn't many of them, but they might be useful:
 * {@see get_metric()}, {@see get_category_from()}.
 *
 * Example:
 * <code>
 * require 'RIT_Webservices.php';
 * $webservice = new RIT_Webservices('login', 'password', 'certificate.pem', 'test');
 * var_dump($webservice->get_metadata());
 * var_dump($webservice->get_objects(array(
 *  'language' => 'ru-RU',
 *  'allForDistributionChannel' => 'true',
 * )));
 * </code>
 *
 * @author Grzegorz Kowalski
 *
 * @todo Complete descriptions of all methods
 * @todo Complete implementation of RIT webservice API (e.g. mass action methods)
 */
class RIT_Webservices
{
	/**
	 * User login string
	 * @var string
	 */
	protected $user;

	/**
	 * RIT instance name, see {@see $instances}
	 * @var string
	 */
  protected $instance;

	/**
	 * Array of options for SoapClient
	 * @var array
	 */
  protected $soap_options;

	/**
	 * Array of options for SoapClient
	 * @var array
	 */
	protected $curl_options;

	// TODO: description
	protected $curl;

	/**
	 * Last XML response (only if $trace parameter of {@see __construct()} is set; otherwise NULL)
	 * @var string
	 */
	public $xml_response;

	/**
	 * Last XML request (only if $trace parameter of {@see __construct()} is set; otherwise NULL)
	 * @var string
	 */
	public $xml_request;

	/**
	 * Array of available RIT instances
	 * @var array
	 */
  public $instances = array(
    'production'  => 'https://maps.pot.gov.pl/rit-soap-server/',
    'test'        => 'https://maps.pot.gov.pl/rit-soap-server/',
  );

/**
 * Class constructor
 *
 * Initializes class with user authentication data and sets target RIT instance.
 * Lack of certificate file results in exception being thrown.
 *
 * Example:
 * <code>
 * $webservice = new RIT_Webservices('login', 'password', 'certificate.pem', 'test');
 * var_dump($webservice->get_metadata());
 * </code>
 *
 * @param string $user     Login
 * @param string $pass     Password
 * @param string $cert     Path and filename of certificate file (*.pem format)
 * @param string $instance RIT instance/environment name from {@see $instances}
 * @param boolean $trace   if true, store request and response XMLs to further inspection {@see $xml_response, @see $xml_request, @see store_trace_data()}
 * @throws Exception
 */
	public function __construct($user, $pass, $cert, $instance = 'production', $trace = false)
  {
		$this->user = $user;
    $this->instance = $instance;
		$this->xml_response = null;
		$this->xml_request = null;

		/* selecting all objects in pl-PL from current state (not cached) took 994 seconds! */
		ini_set("default_socket_timeout", 1000);	//< introduced to fix possible bug in PHP 7.1.8 with stream context timeouts

		$stream_options = array(
			'http' => array(
				'timeout' => 1000.0,	//< should override default_socket_timeout
			),
			'ssl' => array(
				'allow_self_signed'	=> true,
			),
		);

		$stream_context = stream_context_create($stream_options);

    $this->soap_options = array(
      'soap_version' 	 => SOAP_1_1,
			'cache_wsdl' 	   => WSDL_CACHE_MEMORY,
			'encoding' 		   => 'utf8',
      'keep_alive'     => false,
			'stream_context' => $stream_context,
			'trace'					 => $trace,
			'compression'		 => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
    );

	  $this->curl_options = array(
			CURLOPT_RETURNTRANSFER	=> true,
			CURLOPT_FOLLOWLOCATION	=> true,
			CURLOPT_SSL_VERIFYHOST	=> false,
			CURLOPT_SSL_VERIFYPEER	=> false,
			CURLOPT_USERAGENT				=> 'RIT_Webservices',
			CURLOPT_VERBOSE					=> false,
			CURLOPT_NOPROGRESS			=> true,
			//CURLOPT_TIMEOUT					=> 900,
		);

		if ($cert !== null) {
			$this->soap_options['local_cert'] = $cert;
			$this->curl_options[CURLOPT_SSLCERT] = $cert;
		}

		if ($pass !== null) {
			$this->soap_options['passphrase'] = $pass;
			$this->curl_options[CURLOPT_SSLCERTPASSWD] = $pass;
		}

		$this->curl = false;
	}

/**
 * Function called after successfull SoapClient communiation when 'trace' option is enabled (@see __construct()).
 * Override it to refine your monitoring or debug capabilities.
 *
 * Example:
 * <code>
 * class RIT_Webservices_SaveToXML extends RIT_Webservices {
 *   protected function store_trace_data($soap_client_object) {
 *     $name = time();
 *     file_put_contents("request_$name.xml", $soap_client_object->__getLastRequest());
 *     file_put_contents("response_$name.xml", $soap_client_object->__getLastResponse());
 *   }
 * }
 * </code>
 *
 * @param  object $soap_client_object SoapClient object
 */
	protected function store_trace_data($soap_client_object) {
		$this->xml_response = $soap_client_object->__getLastResponse();
		$this->xml_request = $soap_client_object->__getLastRequest();
	}

/**
 * Create new metric object to be used as part of a request object
 *
 * Internal function to help create metric object which should be incorporated into SOAP request.
 *
 * Example:
 * <code>
 * $ws	= $this->get_webservice('MetadataOfRIT');
 * $request = array(
 *   'metric'	=> $this->get_metric(),
 *   'language'	=> $lang,
 * );
 * return $ws->getMetadataOfRIT($request);
 * </code>
 *
 * @return object Metric object
 */
  protected function get_metric()
  {
    return (object) array(
      'distributionChannel'	    => $this->user,
      'username'					      => $this->user,
      'requestUniqueIdentifier'	=> time(),
      'requestDate'				      => date('Y-m-dP'),
    );
  }

/**
 * Function creating SoapClient object for webservice of given name.
 *
 * Example for internal use:
 * <code>
 * $ws	= $this->get_webservice('MetadataOfRIT');
 * // ... creating request object ...
 * return $ws->getMetadataOfRIT($request);
 * </code>
 *
 * Example for manual SoapClient usage:
 * <code>
 * $webservice = new RIT_Webservices('login', 'pass', 'cert.pem', 'env');
 * $client = $webservice->get_webservice('GiveTouristObjects');
 * // ... creating request object
 * $client->addModifyObject($request);
 * </code>
 *
 * @param  string $method_name Name of webservice / method
 * @return SoapClient Properly set-up and ready to use SoapClient instance
 */
  public function get_webservice($method_name)
  {
		if (isset($this->instances[$this->instance])) {
			$url = $this->instances[$this->instance] . $method_name;
		} else {
			$url = $this->instance . $method_name;
		}
    $webservice = new SoapClient("$url?wsdl", $this->soap_options);
    $webservice->__setLocation($url);
    return $webservice;
  }

/**
 * Get all objects satysfying given conditions
 *
 * Example:
 * <code>
 * var_dump($webservice->get_objects(array(
 *   'language' => 'ru-RU',
 *   'allForDistributionChannel' => 'true',
 * )));
 * </code>
 *
 * @param  array  $where 					Search conditions encoded in array to be inserted into request object
 * @param  boolean $remote_cache	Set true to get data from cached data; false otherwise
 * @return object									Response of webservice method call
 *
 * @todo Make test cases
 */
	public function get_objects($where, $remote_cache = false)
  {
    if ($remote_cache === true) {
      $ws	= $this->get_webservice('CollectTouristObjectsCache');
    } else {
			$ws	= $this->get_webservice('CollectTouristObjects');
		}

		$request = array(
			'metric'	=> $this->get_metric(),
			'searchCondition'	=> $where,
		);

		if ($remote_cache === true) {
      $result = $ws->searchTouristObjectsInCache($request);
    } else {
			$result = $ws->searchTouristObjects($request);
		}

		$this->store_trace_data($ws);
		return $result;
	}

/**
 * Get all objects in target language
 *
 * Wrapper function for using get_objects() to get all objects in target language.
 *
 * @param  string  $lang         Language code, see {@see get_languages()}
 * @param  boolean $remote_cache Set true to get data from cached data; false otherwise
 * @return object                Response object from webservice
 * @see    RIT_Webservices::get_languages()
 */
	public function get_all_objects($lang = 'pl-PL', $remote_cache = false) {
		return $this->get_objects(array(
		  'language' => $lang,
		  'allForDistributionChannel' => true,
		), $remote_cache);
	}

	/**
	 * [get_objects_by_attributes description]
	 * @param  [type]  $attributes   [description]
	 * @param  string  $lang         [description]
	 * @return [type]                [description]
	 */
	public function get_objects_by_attributes($attributes, $lang = 'pl-PL') {
		$searchAttributeAnd = array();

		foreach ($attributes as $code => $value) {
			$search_attribute = new \stdClass;
			$search_attribute->attributeCode = $code;
			$search_attribute->valueToSearch = $value;

			$searchAttributeAnd[] = $search_attribute;
		}

		return $this->get_objects(array(
		  'language' => $lang,
		  'allForDistributionChannel' => false,
			'searchAttributeAnd' => $searchAttributeAnd,
		), false);
	}

	/**
	 * Recieve all objects associated with category or categories
	 *
	 * @param  mixed $categories	Single category code (as string) or array of category codes (strings)
	 * @param  string	$lang   		Language code, see {@see get_languages()}
	 * @param  boolean $remote_cache Set true to get data from cached data; false otherwise
	 * @return object    					Response object from webservice
	 *
	 * @todo Make test cases
	 */
	public function get_objects_by_categories($categories, $lang = 'pl-PL', $remote_cache = false) {
		return $this->get_objects(array(
		  'language' => $lang,
		  'allForDistributionChannel' => false,
			'searchCategoryAnd' => array(
				'categoryCode' => $categories,
			),
		), $remote_cache);
	}

/**
 * Recieve single object from RIT database identified by its RIT ID or external (source) ID
 *
 * @param  mixed 	$object_id	RIT ID encoded as int or external ID encoded as object by {@see encode_object_id()} or {@see create_object_id()}
 * @param  string	$lang   		Language code, see {@see get_languages()}
 * @return object    					Response object from webservice
 */
	public function get_object_by_id($object_id, $lang = 'pl-PL') {
		$object = new \stdClass;
		if (is_object($object_id)) {
			$object->identifierSZ = $object_id;
			$object->identifierSZ->distributionChannel = new \stdClass;
			$object->identifierSZ->distributionChannel->name = $this->user;
			$object->identifierSZ->distributionChannel->code = $this->user;
		} else {
			$object->identifierRIT = $object_id;
		}

		return $this->get_objects(array(
		  'language' => $lang,
		  'allForDistributionChannel' => false,
			'objectIdentifier' => $object,
		), false);
	}

	/**
	 * [get_objects_by_modification_date description]
	 *
	 * @param  [type] $date_from [description]
	 * @param  [type] $date_to   [description]
	 * @param  string $lang      Language code, see {@see get_languages()}
	 * @return object            Response object from webservice
	 *
	 * @todo Make test cases
	 */
	public function get_objects_by_modification_date($date_from, $date_to = null, $lang = 'pl-PL', $remote_cache = false) {
		if ($date_to === null) {
			$date_to = date('Y-m-dP');
		}

		$range = new \stdClass;
		$range->dateFrom = $date_from;
		$range->dateTo = $date_to;

		return $this->get_objects(array(
		  'language' => $lang,
		  'allForDistributionChannel' => false,
			'lastModifiedRange' => $range,
		), $remote_cache);
 	}

/**
 * Send new object to RIT database
 *
 * Example:
 * <code>
 * $object = $webservice->create_tourist_object(
 *	 $webservice->encode_object_id(124, 'my_test_objects_table'),
 *	 date('Y-m-dP', time() - 86400), // last modified yesterday
 *	 array(
 *		 'C040', // pokoje goscinne
 *	 ),
 *	 array(
 *	  'A001' => array('pl-PL' => 'Testowa nazwa testowego obiektu PL', 'en-GB' => 'Test name of test object'),
 *	  'A003' => array('pl-PL' => 'Testowy krótki opis testowego obiektu', 'en-GB' => 'Short description'),
 *	  'A004' => array('pl-PL' => 'Testowy długi opis testowego obiektu', 'en-GB' => 'Long description'),
 *	  'A009' => array('pl-PL' => 'mazowieckie'),
 *	  'A010' => array('pl-PL' => 'Warszawa'), // powiat
 *	  'A011' => array('pl-PL' => 'Warszawa'), // gmina
 *	  'A012' => array('pl-PL' => 'Warszawa'), // miejscowosc
 *	  'A013' => array('pl-PL' => 'Ulica'),
 *	  'A014' => array('pl-PL' => 'Testowa ulica'),
 *	  'A015' => array('pl-PL' => '1A'), // numer budynku
 *	  'A016' => array('pl-PL' => '2B'), // numer lokalu
 *	  'A017' => array('pl-PL' => '01-234'), // kod pocztowy
 *	  'A018' => array('pl-PL' => '51.123456,20.123456'), // wspolrzedne geograficzne
 *	  'A019' => array('pl-PL' => array('W mieście', 'W centrum miasta')),
 *	  'A020' => array('pl-PL' => 'Testowy opis dojazdu'),
 *	  'A021' => array('pl-PL' => 'Inny'),	// region turystyczny
 *	  'A044' => array('pl-PL' => '11-11'), // poczatek sezonu
 *	  'A045' => array('pl-PL' => '12-12'), // koniec sezonu
 *	  'A047' => array('pl-PL' => '09-09'), // poczatek sezonu dodatkowego
 *	  'A048' => array('pl-PL' => '10-10'), // koniec sezonu dodatkowego
 *	  'A057' => array('pl-PL' => 'Testowe uwagi dotyczące dostępności'),
 *	  'A059' => array('pl-PL' => '+48 001234567'),
 *	  'A060' => array('pl-PL' => '+48 001234567'),
 *	  'A061' => array('pl-PL' => 'Testowy numer specjalny'),
 *	  'A062' => array('pl-PL' => '+48 123456789'),
 *	  'A063' => array('pl-PL' => '+48 001234567'),
 *	  'A064' => array('pl-PL' => 'test@test.pl'),
 *	  'A065' => array('pl-PL' => 'pot.gov.pl'),
 *	  'A066' => array('pl-PL' => 'GG:123456789'),
 *	  'A069' => array('pl-PL' => '100-200 zł'),
 *	  'A070' => array('pl-PL' => array('Dzieci', 'Rodziny', 'Seniorzy', 'Studenci')), // znizki
 *	  'A086' => array('pl-PL' => 'Gospodarstwa Gościnne'), // przynaleznosc do sieci,
 *	  'A087' => array('pl-PL' => array('Leśniczówka, kwatera myśliwska', 'Apartamenty')), // D016 multiple,
 *	  'A089' => array('pl-PL' => 123),
 *	  'A090' => array('pl-PL' => 45),
 *	  'A091' => array('pl-PL' => 6),
 *	  'A095' => array('pl-PL' => array('Internet bezpłatny', 'Internet', 'Masaż')),
 *	  'A096' => array('pl-PL' => 'Testowe uwagi do miejsc noclegowych', 'en-GB' => 'Accomodation notice'),
 *	 ),
 *	 array(
 *		 $webservice->create_attachment(
 *			 'sample-rectangular-photo.jpg',
 *			 'jpg',
 *			 'https://unsplash.it/400'
 *		 ),
 *	 )
 * );
 * $result = $webservice->add_object($object);
 * </code>
 *
 * @param object $object Tourist object encoded using {@see create_tourist_object()}
 * or manually crafted as 'touristObject' subpart of the addModifyObject request
 *
 * @see create_tourist_object()
 * @throws InvalidArgumentException
 */
	public function add_object($object)
  {
		if (is_array($object)) {
			throw new InvalidArgumentException('expected single object, got array; use add_objects() to add multiple objects at once');
		}

    $ws	= $this->get_webservice('GiveTouristObjects');

		$request = (object) array(
			'metric'	=> $this->get_metric(),
			'touristObject'	=> $object,
		);

		$result = $ws->addModifyObject($request);
		$this->store_trace_data($ws);
		return $result;
	}

	/**
	 * Encode source database ID for touristic data object
	 *
	 * @param  string|int $table_id   	Row ID in source database table
	 * @param  string|null $table_name	(optional) String containing name of source database table if there are multiple tables with touristic objects
	 * or NULL if there exists only one table with touristic data
	 * @return object             			Object to be used as ID in {@see create_tourist_object()}
	 *
	 * @see create_tourist_object()
	 */
	public function encode_object_id($table_id, $table_name = null) {
		$id = new \stdClass;
		$id->identifierType = 'I' . ($table_name? 2 : 1);
		$id->artificialIdentifier = $table_id;
		if ($table_name) {
			$id->databaseTable = $table_name;
		}
		return $id;
	}

	/**
	 * Encode manually created source unique identifier for touristic data object
	 *
	 * In case your touristic objects aren't stored inside database or they can't be identified by simple IDs,
	 * you can construct unique ID manually by whataver means you choose (concatenation of some fields: e.g. title, address; GUID generation;
	 * hash calculation).
	 *
	 * @param  string $unique_string_id	Unique string ID possibly
	 * @return object                 	Object to be used as ID in {@see create_tourist_object()}
	 *
	 * @see create_tourist_object()
	 */
	public function create_object_id($unique_string_id) {
		$id = new \stdClass;
		$id->identifierType = 'I3';
		$id->concatenationOfField = $unique_string_id;
		return $id;
	}

	/**
	 * [create_attachment description]
	 * @param  [type] $name        [description]
	 * @param  [type] $type        [description]
	 * @param  [type] $source      [description]
	 * @param  string $source_type [description]
	 * @return [type]              [description]
	 *
	 * @todo Complete description
	 */
	public function create_attachment($name, $type, $source, $source_type = 'URL') {
		$attachment = new \stdClass;
		$attachment->fileName = $name;
		$attachment->fileType = $type;

		switch ($source_type) {
			case 'ftp':
				$attachment->relativePathToDirectory = $source;
				break;

			case 'base64':
				$attachment->encoded = $source;
				break;

			case 'URL':
			default:
				$attachment->URL = $source;
		}

		return $attachment;
	}

/**
 * Encode tourist data object as subpart of request issued by {@see add_object()}.
 *
 * @param  mixed $object_id      RIT ID encoded as int or external ID encoded as object by {@see encode_object_id()} or {@see create_object_id()}
 * @param  string $last_modified Datetime of last modification in format of 'Y-m-dP'
 * @param  array  $categories    Array of strings with category names
 * @param  array  $attributes    Array of attribute_code=>array(language_code=>value) or attribute_code=>array(language_code=>array(values))
 * @param  array  $attachments	 Array of objects generated by {@see create_attachment()}
 * @return object                Tourist object to be used in {@see add_object()} call
 * @see RIT_Webservices::add_object()
 * @see RIT_Webservices::encode_object_id()
 * @see RIT_Webservices::create_object_id()
 * @see create_attachment()
 */
	public function create_tourist_object($object_id, $last_modified, $categories, $attributes, $attachments = array()) {
		$object = new \stdClass;
		if (is_object($object_id)) {
			$object->touristObjectIdentifierSZ = $object_id;
			$object->touristObjectIdentifierSZ->distributionChannel = new \stdClass;
			$object->touristObjectIdentifierSZ->distributionChannel->name = $this->user;
			$object->touristObjectIdentifierSZ->distributionChannel->code = $this->user;
			$object->touristObjectIdentifierSZ->lastModified = $last_modified;
		} else {
			$object->touristObjectIdentifierRIT = new \stdClass;
			$object->touristObjectIdentifierRIT->identifierRIT = $object_id;
		}

		$object->categories = new \stdClass;
		$object->categories->category = array();
		foreach ($categories as $category) {
			$object->categories->category[] = (object) array('code' => $category);
		}

    $_attributes = $this->get_attributes();

		$object->attributes = new \stdClass;
		$object->attributes->attribute = array();
		foreach ($attributes as $_attribute_code => $_attribute_lang_values) {
			$tmp_object = array();

			foreach ($_attribute_lang_values as $_attribute_language => $_attribute_values) {
				if ($this->is_translatable_from($_attributes, $_attribute_code)) {
					$tmp_object[] = array('value' => $_attribute_values, 'language' => $_attribute_language);
				} else {
					$tmp_object[] = array('value' => $_attribute_values, 'language' => 'all');
					break;
				}
			}

			$object->attributes->attribute[] = (object) array(
				'attrVals' => $tmp_object,
				'code' => $_attribute_code,
			);
		}
		if (!empty($attachments)) {
			$object->binaryDocuments = new \stdClass;

			foreach ($attachments as $attachment) {
				if (isset($attachment->URL)) $object->binaryDocuments->documentURL[] = $attachment;
				if (isset($attachment->relativePathToDirectory)) $object->binaryDocuments->documentFile[] = $attachment;
				if (isset($attachment->encoded)) $object->binaryDocuments->documentBase64[] = $attachment;
			}
		}
		return $object;
	}

	/**
	 * Send a package of new objects to RIT database and get transaction ID in response (to recieve import reports at later time).
	 *
	 * @param mixed $objects	Single object or array of tourist objects encoded using {@see create_tourist_object()}
	 * or manually crafted as 'touristObject' subpart of the addModifyObjects request
	 *
	 * @todo Complete description
	 * @todo Make test case for passing single object
	 */
	public function add_objects($objects) {
		$ws	= $this->get_webservice('GiveTouristObjects');

		$request = (object) array(
			'metric'	=> $this->get_metric(),
			'touristObject'	=> $objects,
		);

		$result = $ws->addModifyObjects($request);
		$this->store_trace_data($ws);
		return $result;
	}

	/**
	 * [delete_object description]
	 * @param		mixed $object_id		RIT ID encoded as int or external ID encoded as object by {@see encode_object_id()} or {@see create_object_id()}
	 * @return [type]            [description]
	 *
	 * @todo Make test case
	 * @todo Complete description
	 */
	public function delete_object($object_id) {
		$ws	= $this->get_webservice('GiveTouristObjects');

		$request = new \stdClass;
		$request->metric = (object) $this->get_metric();

		if (is_object($object_id)) {
			$request->identifierSZ = $object_id;
			$request->identifierSZ->distributionChannel = new \stdClass;
			$request->identifierSZ->distributionChannel->name = $this->user;
			$request->identifierSZ->distributionChannel->code = $this->user;
			$request->identifierSZ->lastModified = date('Y-m-dP');
		} else {
			$request->identifierRIT = new \stdClass;
			$request->identifierRIT->identifierRIT = $object_id;
		}

		$result = $ws->delObject($request);
		$this->store_trace_data($ws);
		return $result;
	}

	/**
	 * @ignore
	 */
	public function delete_objects() {
		throw new Exception("Metod not implemented.");
	}

	/**
	 * @param  string|int $transaction_id	Transaction ID number recieved from mass action methods
	 * @return object                			Response object containing an array of reports
	 *
	 * @see add_objects()
	 * @see delete_objects()
	 */
	public function get_report($transaction_id) {
		$ws	= $this->get_webservice('GiveTouristObjects');

		$request = array(
			'metric'	=> $this->get_metric(),
			'transactionIdentifier'	=> $transaction_id,
		);

		$result = $ws->getReport($request);
		$this->store_trace_data($ws);
		return $result;
	}

/**
 * Recieve all available metadata from metadata webservice
 *
 * Notice: metadata webservice does not send complete RIT metadata. Some datasets are ommited due to their large size which considerably
 * increses response times of the webservice. Those datasets can be found elsewhere in public databases, e.g. list of cities or regions can be found
 * in public TERYT databases.
 *
 * Example:
 * <code>
 * $webservice = new RIT_Webservices('login', 'password', 'certificate.pem', 'test');
 * $metadata = $webservice->get_metadata();
 * var_dump($metadata);
 * </code>
 *
 * @param  string $lang Language code, see {@see get_languages()}
 * @return object       Response object from webservice call
 */
	public function get_metadata($lang = 'pl-PL')
  {
		$ws	= $this->get_webservice('MetadataOfRIT');

		$request = array(
			'metric'	=> $this->get_metric(),
			'language'	=> $lang,
		);
		$result = $ws->getMetadataOfRIT($request);
		$this->store_trace_data($ws);
		return $result;
	}

	/**
	 * [get_metadata_last_modification_date description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */
	public function get_metadata_last_modification_date($lang = 'pl-PL')
	{
		return $this->get_metadata($lang)->lastModificationDate;
	}

	/**
	 * [get_attributes description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */
	public function get_attributes($lang = 'pl-PL')
	{
		return $this->get_metadata($lang)->ritAttribute;
	}

	/**
	 * [get_attribute_from description]
	 * @param  [type] $_attributes [description]
	 * @param  [type] $_code       [description]
	 * @return [type]              [description]
	 *
	 * @todo Complete description
	 */
	protected function get_attribute_from($_attributes, $_code)
	{
		$_result = null;
		foreach ($_attributes as $_attribute) {
			if ($_attribute->code == $_code) {
				$_result = $_attribute;
				break;
			}
		}
		return $_result;
	}

	/**
	 * [get_attribute description]
	 * @param  [type] $_code [description]
	 * @param  string $_lang Language code, see {@see get_languages()}
	 * @return [type]        [description]
	 *
	 * @todo Complete description
	 */
	public function get_attribute($_code, $_lang = 'pl-PL')
	{
		return $this->get_attribute_from($this->get_attributes($_lang), $_code);
	}

	/**
	 * [is_translatable_from description]
	 * @param  [type]  $_attributes [description]
	 * @param  [type]  $_code       [description]
	 * @return boolean              [description]
	 *
	 * @todo Complete description
	 */
	protected function is_translatable_from($_attributes, $_code)
	{
		switch ($_code) {
			case 'A009':	// wojewodztwo
			case 'A010':	// powiat
			case 'A011':	// gmina
			case 'A012':	// miejscowosc
				return false;
			default:
		}

		$_type = $this->get_attribute_from($_attributes, $_code)->typeValidator;

		switch ($_type) {
			case 'SHORT_TEXT':
			case 'LONG_TEXT':
			case 'MULTIPLY_LIST':
			case 'SINGLE_LIST':
				return true;

			case 'NUMBER':
			case 'BOOLEAN':
			case 'DATE':
			case 'COMPLEX':
			default:
		}

		return false;
	}

	/**
	 * [get_categories description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */
	public function get_categories($lang = 'pl-PL')
	{
		return $this->get_metadata($lang)->ritCategory;
	}

	/**
	 * Returns array of all childless categories.
	 *
	 * @return array
	 */
	public function get_childless_categories()
	{
		$categories = $this->get_categories();

		$all_categories = [];
		$parent_categories = [];
		$childless_categories = [];

		foreach ($categories as $category) {
		  if (in_array($category->code, $all_categories) === false) {
		    $all_categories[] = $category->code;
		  }
		  if (isset($category->parentCode) && in_array($category->parentCode, $parent_categories) === false) {
		    $parent_categories[] = $category->parentCode;
		  }
		}

		$childless_categories = array_diff($all_categories, $parent_categories);
		return $childless_categories;
	}

	/**
	 * [get_category_from description]
	 * @param  [type]  $_cache              [description]
	 * @param  [type]  $_code               [description]
	 * @param  boolean $_inherit_attributes [description]
	 * @param  string  $_lang               Language code, see {@see get_languages()}
	 * @return [type]                       [description]
	 *
	 * @todo Complete description
	 */
	protected function get_category_from($_cache, $_code, $_inherit_attributes = true, $_lang = 'pl-PL')
	{
		if ($_cache === null) {
			$_categories = $this->get_categories($_lang);
		} else {
			$_categories = $_cache;
		}

		$_result = null;
		foreach ($_categories as $_category) {
			if ($_category->code == $_code) {
				$_result = $_category;
				if ($_inherit_attributes === true && isset($_result->parentCode)) {
					$_result->attributeCodes->attributeCode = array_merge(
						$_result->attributeCodes->attributeCode,
						$this->get_category_from($_cache, $_result->parentCode, true, $_lang)->attributeCodes->attributeCode
					);
				}
				break;
			}
		}
		return $_result;
	}

	/**
	 * [get_category description]
	 * @param  [type]  $_code               [description]
	 * @param  boolean $_inherit_attributes [description]
	 * @param  string  $_lang               Language code, see {@see get_languages()}
	 * @return [type]                       [description]
	 *
	 * @todo Complete description
	 */
	public function get_category($_code, $_inherit_attributes = true, $_lang = 'pl-PL')
	{
		return $this->get_category_from(null, $_code, $_inherit_attributes, $_lang);
	}

	/**
	 * [get_dictionaries description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */
	public function get_dictionaries($lang = 'pl-PL')
	{
		return $this->get_metadata($lang)->ritDictionary;
	}

	/**
	 * [get_dictionary description]
	 * @param  [type] $code [description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */
	public function get_dictionary($code, $lang = 'pl-PL')
	{
		$dictionaries = $this->get_dictionaries($lang);
		foreach ($dictionaries as $dictionary) {
			if ($dictionary->code == $code) {
				return $dictionary;
			}
		}
		return null;
	}

	/**
	 * [get_dictionary_title description]
	 * @param  [type] $code [description]
	 * @param  string $lang Language code, see {@see get_languages()}
	 * @return [type]       [description]
	 *
	 * @todo Complete description
	 */

	public function get_dictionary_title($code, $lang = 'pl-PL')
	{
		$dictionary = $this->get_dictionary($code, $lang);
		if ($dictionary) {
			return $dictionary->name;
		}
		return null;
	}

/**
 * [get_dictionary_values description]
 * @param  [type] $code [description]
 * @param  string $lang Language code, see {@see get_languages()}
 * @return [type]       [description]
 *
 * @todo Complete description
 */
	public function get_dictionary_values($code, $lang = 'pl-PL')
	{
		$dictionary = $this->get_dictionary($code, $lang);
		if ($dictionary) {
			return $dictionary->value;
		}
		return null;
	}

/**
 * Get array of all language codes
 *
 * Convenient wrapper for {@see get_dictionary_values()} to get contents of languages dictionary.
 * This should give the same results regardless of the value of optional *$lang* parameter, so you
 * can always safely call this method without any arguments.
 *
 * Example:
 * <code>
 * $webservice = new RIT_Webservices('login', 'password', 'certificate.pem', 'test');
 * var_dump($webservice->get_languages());
 * </code>
 *
 * Example output:
 * <pre>
 * array(22) {
 *   [0]=> string(5) "en-GB"
 *   // ...
 *   [21]=> string(5) "zh-CN"
 * }
 * </pre>
 *
 * @see RIT_Webservices::get_dictionary_values()
 *
 * @param  string $lang (optional) Language code
 * @return array        Array of all language codes
 */
	public function get_languages($lang = 'pl-PL')
	{
		return $this->get_dictionary_values('L001', $lang);
	}

	public function get_file($url) {
		if ($this->curl === false) {
			$this->curl = curl_init();

			if ($this->curl === false) {
				throw new Exception('Cannot init cURL!');
			}
		}

		curl_setopt_array($this->curl,
			array(
				CURLOPT_URL => html_entity_decode($url),
				CURLOPT_POST => FALSE,
				CURLOPT_HTTPHEADER => array()
			)
			+ $this->curl_options
		);

		$result = curl_exec($this->curl);

		if ($result === false) {
			throw new Exception(curl_error($this->curl));
		}

		return $result;
	}

	/**
	 * @param  string $date_from [description]
	 * @param  string $date_to   [description]
	 * @return object            Response object from webservice call
	 *
	 * @todo Complete description
	 */
	public function get_events($date_from, $date_to) {
		$ws	= $this->get_webservice('GetTouristObjectEvents');

		$request = array(
			'metric'	=> $this->get_metric(),
			'criteria'	=> array(
				'dateFrom' => $date_from,
				'dateTo' => $date_to,
			),
		);

		$result = $ws->getEvents($request);
		$this->store_trace_data($ws);
		return $result;
	}

	/**
	 *
	 * Example:
	 * <code>
	 * $webservice = new RIT_Webservices('login', 'password', 'certificate.pem', 'test');
	 * var_dump($webservice->get_object_languages('486762'));
	 * </code>
	 *
	 * Example output:
	 * <pre>
	 * object(stdClass)#6 (1) {
	 *   ["language"]=>
	 *   array(7) {
	 *     [0]=>
	 *     string(5) "de-DE"
	 *     [1]=>
	 *     string(5) "es-ES"
	 *     [2]=>
	 *     string(5) "fr-FR"
	 *     [3]=>
	 *     string(5) "it-IT"
	 *     [4]=>
	 *     string(5) "ru-RU"
	 *     [5]=>
	 *     string(5) "en-EN"
	 *     [6]=>
	 *     string(5) "pl-PL"
	 *   }
	 * }
	 * </pre>
	 *
	 * @param  string $object_id RIT ID encoded as int or external ID encoded as object by {@see encode_object_id()} or {@see create_object_id()}
	 * @return object            Response object from webservice call
	 *
	 * @todo Complete description
	 */
	public function get_object_languages($object_id) {
		$ws	= $this->get_webservice('GetTouristObjectLanguages');

		$request = new \stdClass;
		$request->metric = (object) $this->get_metric();
		$request->objectIdentifier = new \stdClass;

		if (is_object($object_id)) {
			$request->objectIdentifier->identifierSZ = $object_id;
			$request->objectIdentifier->identifierSZ->distributionChannel = new \stdClass;
			$request->objectIdentifier->identifierSZ->distributionChannel->name = $this->user;
			$request->objectIdentifier->identifierSZ->distributionChannel->code = $this->user;
			$request->objectIdentifier->identifierSZ->lastModified = date('Y-m-dP');
		} else {
			$request->objectIdentifier->identifierRIT = $object_id;
		}

		$result = $ws->getLanguages($request);
		$this->store_trace_data($ws);
		return $result;
	}
}
