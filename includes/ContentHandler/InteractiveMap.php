<?php

namespace MediaWiki\Extension\SubnauticaTweaks\ContentHandler;

class InteractiveMap extends \TextContentHandler {
    public const CONTENT_MODEL_ID = 'interactivemap';

	public function __construct( $modelId = self::CONTENT_MODEL_ID ) {
		parent::__construct( $modelId );
	}
}