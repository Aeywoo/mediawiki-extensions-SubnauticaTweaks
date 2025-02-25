<?php

namespace MediaWiki\Extension\SubnauticaTweaks\ContentHandler;

class GeoJSON extends \JsonContentHandler {
    public const CONTENT_MODEL_ID = 'GeoJSON';

	public function __construct( $modelId = self::CONTENT_MODEL_ID ) {
		parent::__construct( $modelId );
	}
}