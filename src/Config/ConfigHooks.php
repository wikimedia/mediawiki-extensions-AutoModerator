<?php

namespace AutoModerator\Config;

use AutoModerator\Config\Validation\ConfigValidatorFactory;
use Content;
use FormatJson;
use IContextSource;
use JsonContent;
use MediaWiki\Config\Config;
use MediaWiki\Content\Hook\JsonValidateSaveHook;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Status\Status;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use StatusValue;
use TextContent;

class ConfigHooks implements
	EditFilterMergedContentHook,
	JsonValidateSaveHook,
	PageSaveCompleteHook
{
	private ConfigValidatorFactory $configValidatorFactory;
	private WikiPageConfigLoader $configLoader;
	private TitleFactory $titleFactory;
	private Config $config;

	/**
	 * @param ConfigValidatorFactory $configValidatorFactory
	 * @param WikiPageConfigLoader $configLoader
	 * @param TitleFactory $titleFactory
	 * @param Config $config
	 */
	public function __construct(
		ConfigValidatorFactory $configValidatorFactory,
		WikiPageConfigLoader $configLoader,
		TitleFactory $titleFactory,
		Config $config
	) {
		$this->configValidatorFactory = $configValidatorFactory;
		$this->configLoader = $configLoader;
		$this->titleFactory = $titleFactory;
		$this->config = $config;
	}

	/**
	 * @inheritDoc
	 */
	public function onEditFilterMergedContent(
		IContextSource $context,
		Content $content,
		Status $status,
		$summary,
		User $user,
		$minoredit
	) {
		// Check whether this is a config page edited
		$title = $context->getTitle();
		foreach ( $this->configValidatorFactory->getSupportedConfigPages() as $configTitle ) {
			if ( $title->equals( $configTitle ) ) {
				// Check content model
				if (
					$content->getModel() !== CONTENT_MODEL_JSON ||
					!( $content instanceof TextContent )
				) {
					$status->fatal(
						'automoderator-config-validator-contentmodel-mismatch',
						$content->getModel()
					);
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onJsonValidateSave(
		JsonContent $content, PageIdentity $pageIdentity, StatusValue $status
	) {
		foreach ( $this->configValidatorFactory->getSupportedConfigPages() as $configTitle ) {
			if ( $pageIdentity->isSamePageAs( $configTitle ) ) {
				$data = FormatJson::parse( $content->getText(), FormatJson::FORCE_ASSOC )->getValue();
				$status->merge(
					$this->configValidatorFactory
						// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
						->newConfigValidator( TitleValue::castPageToLinkTarget( $pageIdentity ) )
						->validate( $data )
				);
				if ( !$status->isGood() ) {
					// JsonValidateSave expects a fatal status on failure, but the validator uses isGood()
					$status->setOK( false );
				}
				return $status->isOK();
			}
		}
	}

	/**
	 * Invalidate configuration cache when needed.
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		DeferredUpdates::addCallableUpdate( function () use ( $wikiPage ) {
			$title = $wikiPage->getTitle();
			foreach ( $this->configValidatorFactory->getSupportedConfigPages() as $configTitle ) {
				if ( $title->equals( $configTitle ) ) {
					$this->configLoader->invalidate( $configTitle );
				}
			}
		} );
	}
}
