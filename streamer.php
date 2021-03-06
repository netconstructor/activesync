<?php
/***********************************************
* File      :   streamer.php
* Project   :   Z-Push
* Descr     :   This file handles streaming of
*                WBXML objects. It must be
*                subclassed so the internals of
*                the object can be specified via
*                $mapping. Basically we set/read
*                the object variables of the
*                subclass according to the mappings
*
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once("zpushdtd.php");

define('STREAMER_VAR', 1);
define('STREAMER_ARRAY', 2);
define('STREAMER_TYPE', 3);

define('STREAMER_TYPE_DATE', 1);
define('STREAMER_TYPE_HEX', 2);
define('STREAMER_TYPE_DATE_DASHES', 3);
define('STREAMER_TYPE_MAPI_STREAM', 4);


class Streamer {
    var $_mapping;

    var $content;
    var $attributes;
    var $flags;
    var $_setread;
    var $_setchange;
    var $_setflag;

    function Streamer($mapping) {
        $this->_mapping = $mapping;
        $this->flags = false;
		$this->_setflag = false;
		$this->_setchange = false;
		$this->_setread = false;
		$this->_setcategories = false;
    }

    // Decodes the WBXML from $input until we reach the same depth level of WBXML. This
    // means that if there are multiple objects at this level, then only the first is decoded
    // SubOjects are auto-instantiated and decoded using the same functionality
    function decode(&$decoder) {

		// START HACK dw2412
		// We need this just for decoding items sent by HTC Android Devices (HTC DESIRE Z - maybe others!) the right way...
		switch (get_class($this)) {
			case "SyncAppointment" :
				if (!isset($this->_mapping[SYNC_POOMCAL_BODY]))
					$this->_mapping += array(
    			                SYNC_POOMCAL_BODY => array (STREAMER_VAR => "body"),
                			    SYNC_POOMCAL_BODYTRUNCATED => array (STREAMER_VAR => "bodytruncated"),
			                    SYNC_POOMCAL_RTF => array (STREAMER_VAR => "rtf"),
    	    				);
				break;
			case "SyncContact" :
				if (!isset($this->_mapping[SYNC_POOMCONTACTS_BODY]))
				    $this->_mapping += array(
								SYNC_POOMCONTACTS_RTF => array (STREAMER_VAR => "rtf"),
					        	SYNC_POOMCONTACTS_BODY => array (STREAMER_VAR => "body"),
					        	SYNC_POOMCONTACTS_BODYSIZE => array (STREAMER_VAR => "bodysize"),
					        	SYNC_POOMCONTACTS_BODYTRUNCATED => array (STREAMER_VAR => "bodytruncated"),
				    	    );
    	    	break;
		}
		// END HACK dw2412

        while(1) {
            $entity = $decoder->getElement();
		    if (isset($entity[EN_TAG])) {
				switch ($entity[EN_TAG]) {
				    case "POOMMAIL:Read" : 
				    	$this->_setread=true; break;
				    case "POOMMAIL:Flag" : 
				    	$this->_setflag=true; break;
				    case "POOMMAIL:Categories" : 
				    	$this->_setcategories=true; break;
				    default	: 
				    	$this->_setflag=false; 
						$this->_setread=false; 
						$this->_setcategories=false; 
						$this->_setchange=true;
				};
			};

            if($entity[EN_TYPE] == EN_TYPE_STARTTAG) {
                if(! ($entity[EN_FLAGS] & EN_FLAGS_CONTENT) && (isset($entity[EN_TAG]) && $entity[EN_TAG] != '' && isset($this->_mapping[$entity[EN_TAG]]))) {
                    $map = $this->_mapping[$entity[EN_TAG]];
                    if(!isset($map[STREAMER_TYPE])) {
                        $this->$map[STREAMER_VAR] = "";
                    } else if ($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES ) {
                        $this->$map[STREAMER_VAR] = "";
                    } else if ($map[STREAMER_TYPE] == "SyncPoommailFlag") { // added dw2412 to support empty flag = flag to delete
						$this->poommailflag = new SyncPoommailFlag();
		    		    $this->poommailflag->flagstatus="";
                    }
                    continue;
                } else if (! ($entity[EN_FLAGS] & EN_FLAGS_CONTENT) && (!isset($entity[EN_TAG]) || $entity[EN_TAG] == '' || !isset($this->_mapping[$entity[EN_TAG]])))
                	debugLog("Streamer::DEBUGDEBUGDEBUG:".print_r($entity,true));
                // Found a start tag
                if(!isset($this->_mapping[$entity[EN_TAG]])) {
                    // This tag shouldn't be here, abort
                    debug("Tag " . $entity[EN_TAG] . " unexpected in type XML type " . get_class($this));
                    return false;
                } else {
                    $map = $this->_mapping[$entity[EN_TAG]];

                    // Handle an array
                    if(isset($map[STREAMER_ARRAY])) {
                        while(1) {
                            if(!$decoder->getElementStartTag($map[STREAMER_ARRAY]))
                                break;
                            if(isset($map[STREAMER_TYPE])) {
                                $decoded = new $map[STREAMER_TYPE];
                                $decoded->decode($decoder);
                            } else {
                                $decoded = $decoder->getElementContent();
                            }

                            if(!isset($this->$map[STREAMER_VAR]))
                                $this->$map[STREAMER_VAR] = array($decoded);
                            else
                                array_push($this->$map[STREAMER_VAR], $decoded);

                            if(!$decoder->getElementEndTag())
                                return false;
                        }
                        if(!$decoder->getElementEndTag())
                            return false;
                    } else { // Handle single value
                        if(isset($map[STREAMER_TYPE])) {
                            // Complex type, decode recursively
                            if($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES) {
                                $decoded = $this->parseDate($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            } else if($map[STREAMER_TYPE] == STREAMER_TYPE_HEX) {
                                $decoded = hex2bin($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            } else {
                                $subdecoder = new $map[STREAMER_TYPE]();
                                if($subdecoder->decode($decoder) === false)
                                    return false;

                                $decoded = $subdecoder;

                                if(!$decoder->getElementEndTag()) {
                                    debug("No end tag for " . $entity[EN_TAG]);
                                    return false;
                                }
                            }
                        } else {
                            // Simple type, just get content
                            $decoded = $decoder->getElementContent();

                            if($decoded === false) {
//                                debug("Unable to get content for " . $entity[EN_TAG]);
//                                return false;
								// the tag is declared to have content, but no content is available.
								// set an empty content
								$decoded = "";
                            }

                            if(!$decoder->getElementEndTag()) {
                                debug("Unable to get end tag for " . $entity[EN_TAG]);
                                return false;
                            }
                        }
                        // $decoded now contains data object (or string)
                        $this->$map[STREAMER_VAR] = $decoded;
                    }
                }
            }
            else if($entity[EN_TYPE] == EN_TYPE_ENDTAG) {
                $decoder->ungetElement($entity);
                break;
            }
            else {
                debug("Unexpected content in type");
                break;
            }
        }
    }

    // Encodes this object and any subobjects - output is ordered according to mapping
    function encode(&$encoder) {
        $attributes = isset($this->attributes) ? $this->attributes : array();

        foreach($this->_mapping as $tag => $map) {
            if(isset($this->$map[STREAMER_VAR])) {
                // Variable is available
                if(is_object($this->$map[STREAMER_VAR])) {
                    // Subobjects can do their own encoding
                    $encoder->startTag($tag);
                    $this->$map[STREAMER_VAR]->encode($encoder);
                    $encoder->endTag();
                } else if(isset($map[STREAMER_ARRAY])) {
                    // Array of objects
                    $encoder->startTag($tag); // Outputs array container (eg Attachments)
                    foreach ($this->$map[STREAMER_VAR] as $element) {
                        if(is_object($element)) {
                            $encoder->startTag($map[STREAMER_ARRAY]); // Outputs object container (eg Attachment)
                            $element->encode($encoder);
                            $encoder->endTag();
                        } else {
                            if(strlen($element) == 0)
                                  // Do not output empty items. Not sure if we should output an empty tag with $encoder->startTag($map[STREAMER_ARRAY], false, true);
                                  ;
                            else {
                                $encoder->startTag($map[STREAMER_ARRAY]);
                                $encoder->content($element);
                                $encoder->endTag();
                            }
                        }
                    }
                    $encoder->endTag();
                } else {
                    // Simple type
                    if(strlen($this->$map[STREAMER_VAR]) == 0) {
                          // Do not output empty items. See above: $encoder->startTag($tag, false, true);
                        continue;
                    } else if ($encoder->_multipart == true &&
                		($tag == SYNC_AIRSYNCBASE_DATA ||
                		 $tag == SYNC_AIRSYNCBASE_ATTACHMENT ||
                		 $tag == SYNC_ITEMOPERATIONS_DATA)) {  // START ADDED dw2412 to support mulitpart output
                	$encoder->_bodyparts[] = $this->$map[STREAMER_VAR];
                	$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
                	$encoder->content("".(sizeof($encoder->_bodyparts)-1)."");
                	$encoder->endTag();
			continue; // END ADDED dw2412 to support mulitpart output
                    } else if ($encoder->_multipart == false &&
                		($tag == SYNC_ITEMOPERATIONS_DATA)) {  // START ADDED dw2412 to support mulitpart output
                	$encoder->startTag($tag);
                	$encoder->content(base64_encode($this->$map[STREAMER_VAR]));
                	$encoder->endTag();
			continue; // END ADDED dw2412 to support mulitpart output
                    } else
                        $encoder->startTag($tag);

                    if(isset($map[STREAMER_TYPE]) && ($map[STREAMER_TYPE] == STREAMER_TYPE_DATE || $map[STREAMER_TYPE] == STREAMER_TYPE_DATE_DASHES)) {
                        if($this->$map[STREAMER_VAR] != 0) // don't output 1-1-1970
                            $encoder->content($this->formatDate($this->$map[STREAMER_VAR], $map[STREAMER_TYPE]));
                    } else if(isset($map[STREAMER_TYPE]) && $map[STREAMER_TYPE] == STREAMER_TYPE_HEX) {
                        $encoder->content(strtoupper(bin2hex($this->$map[STREAMER_VAR])));
                    } else if(isset($map[STREAMER_TYPE]) && $map[STREAMER_TYPE] == STREAMER_TYPE_MAPI_STREAM) {
                        $encoder->content($this->$map[STREAMER_VAR]);
                    } else if ($tag == SYNC_POOMMAIL2_CONVERSATIONINDEX ||
                	$tag == SYNC_POOMMAIL2_CONVERSATIONID) {
                    	$encoder->contentopaque($this->$map[STREAMER_VAR]);
            	    } else {
                    	$encoder->content($this->$map[STREAMER_VAR]);
                    }
                    $encoder->endTag();
                }
            }
        }
        // Output our own content
        if(isset($this->content))
            $encoder->content($this->content);

    }



    // Oh yeah. This is beautiful. Exchange outputs date fields differently in calendar items
    // and emails. We could just always send one or the other, but unfortunately nokia's 'Mail for
    // exchange' depends on this quirk. So we have to send a different date type depending on where
    // it's used. Sigh.
    function formatDate($ts, $type) {
        if($type == STREAMER_TYPE_DATE)
            return gmstrftime("%Y%m%dT%H%M%SZ", $ts);
        else if($type == STREAMER_TYPE_DATE_DASHES)
            return gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $ts);
    }

    function parseDate($ts) {
        if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $ts, $matches)) {
            if ($matches[1] >= 2038){
                $matches[1] = 2038;
                $matches[2] = 1;
                $matches[3] = 18;
                $matches[4] = $matches[5] = $matches[6] = 0;
            }
            return gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
        } else if (preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})/", $ts, $matches)) { // Fixes SAMSUNG Date Problem. Samsung send in Contacts dates only the date without time information... unfortunately...
            if ($matches[1] >= 2038){
                $matches[1] = 2038;
                $matches[2] = 1;
                $matches[3] = 18;
            }
            $matches[4] = $matches[5] = $matches[6] = 0;
            return gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
        }
        return 0;
    }
};

?>