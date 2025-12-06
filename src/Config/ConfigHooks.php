<?php

namespace AutoModerator\Config;

use AutoModerator\Config\Validation\ConfigValidatorFactory;
use MediaWiki\Config\Config;
use MediaWiki\Content\Content;
use MediaWiki\Content\Hook\JsonValidateSaveHook;
use MediaWiki\Content\JsonContent;
use MediaWiki\Content\TextContent;
use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Status\Status;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use StatusValue;

class ConfigHooks implements
	EditFilterMergedContentHook,
	JsonValidateSaveHook,
	PageSaveCompleteHook
{
	public function __construct(
		private readonly ConfigValidatorFactory $configValidatorFactory,
		private readonly WikiPageConfigLoader $configLoader,
		private readonly TitleFactory $titleFactory,
		private readonly Config $config,
	) {
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
