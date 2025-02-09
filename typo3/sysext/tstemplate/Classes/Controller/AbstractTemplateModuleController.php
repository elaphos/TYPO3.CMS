<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Tstemplate\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract class with helper methods for single 3rd level Template module controllers.
 *
 * @internal This is a specific Backend Controller implementation and is not considered part of the Public TYPO3 API.
 */
abstract class AbstractTemplateModuleController
{
    protected IconFactory $iconFactory;
    protected UriBuilder $uriBuilder;
    protected ConnectionPool $connectionPool;

    public function injectIconFactory(IconFactory $iconFactory): void
    {
        $this->iconFactory = $iconFactory;
    }

    public function injectUriBuilder(UriBuilder $uriBuilder)
    {
        $this->uriBuilder = $uriBuilder;
    }

    public function injectConnectionPool(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    protected function addPreviewButtonToDocHeader(ModuleTemplate $view, int $pageId, int $dokType): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // Don't add preview button for sysfolders and recycler by default, and look up TS config options
        $excludedDokTypes = [
            PageRepository::DOKTYPE_RECYCLER,
            PageRepository::DOKTYPE_SYSFOLDER,
            PageRepository::DOKTYPE_SPACER,
        ];
        $pagesTsConfig = BackendUtility::getPagesTSconfig($pageId);
        if (isset($pagesTsConfig['TCEMAIN.']['preview.']['disableButtonForDokType'])) {
            $excludedDokTypes = GeneralUtility::intExplode(
                ',',
                $pagesTsConfig['TCEMAIN.']['preview.']['disableButtonForDokType'],
                true
            );
        }

        if ($pageId && !in_array($dokType, $excludedDokTypes, true)) {
            $previewDataAttributes = PreviewUriBuilder::create($pageId)
                ->withRootLine(BackendUtility::BEgetRootLine($pageId))
                ->buildDispatcherDataAttributes();
            $viewButton = $buttonBar->makeLinkButton()
                ->setHref('#')
                ->setDataAttributes($previewDataAttributes ?? [])
                ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
                ->setIcon($this->iconFactory->getIcon('actions-view-page', Icon::SIZE_SMALL));
            $buttonBar->addButton($viewButton, ButtonBar::BUTTON_POSITION_LEFT, 99);
        }
    }

    protected function addShortcutButtonToDocHeader(ModuleTemplate $view, string $moduleIdentifier, array $pageInfo, int $pageId): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $shortcutTitle = sprintf(
            '%s: %s [%d]',
            $languageService->sL('LLL:EXT:tstemplate/Resources/Private/Language/locallang_mod.xlf:mlang_labels_tablabel'),
            BackendUtility::getRecordTitle('pages', $pageInfo),
            $pageId
        );
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier($moduleIdentifier)
            ->setDisplayName($shortcutTitle)
            ->setArguments(['id' => $pageId]);
        $buttonBar->addButton($shortcutButton);
    }

    /**
     * Create template if requested.
     */
    protected function createTemplateIfRequested(ServerRequestInterface $request, int $pageId, int $afterExistingTemplateId = 0): int
    {
        $languageService = $this->getLanguageService();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $recordData = [];
        if ($request->getParsedBody()['createExtension'] ?? $request->getQueryParams()['createExtension'] ?? false) {
            $recordData['sys_template']['NEW'] = [
                'pid' => $afterExistingTemplateId ? -1 * $afterExistingTemplateId : $pageId,
                'title' => '+ext',
            ];
            $dataHandler->start($recordData, []);
            $dataHandler->process_datamap();
        } elseif ($request->getParsedBody()['newWebsite'] ?? $request->getQueryParams()['newWebsite'] ?? false) {
            $recordData['sys_template']['NEW'] = [
                'pid' => $pageId,
                'title' => $languageService->sL('LLL:EXT:tstemplate/Resources/Private/Language/locallang.xlf:titleNewSite'),
                'sorting' => 0,
                'root' => 1,
                'clear' => 3,
                'config' => "\n"
                    . "# Default PAGE object:\n"
                    . "page = PAGE\n"
                    . "page.10 = TEXT\n"
                    . "page.10.value = HELLO WORLD!\n",
            ];
            $dataHandler->start($recordData, []);
            $dataHandler->process_datamap();
        }
        return (int)($dataHandler->substNEWwithIDs['NEW'] ?? 0);
    }

    /**
     * Get closest page row that has a template up in rootline
     */
    protected function getClosestAncestorPageWithTemplateRecord(int $pageId): array
    {
        $rootLine = BackendUtility::BEgetRootLine($pageId);
        foreach ($rootLine as $rootlineNode) {
            if ($this->getFirstTemplateRecordOnPage((int)$rootlineNode['uid'])) {
                return $rootlineNode;
            }
        }
        return [];
    }

    /**
     * Get an array of all template records on a page.
     */
    protected function getAllTemplateRecordsOnPage(int $pageId): array
    {
        if (!$pageId) {
            return [];
        }
        $result = $this->getTemplateQueryBuilder($pageId)->executeQuery();
        $templateRows = [];
        while ($row = $result->fetchAssociative()) {
            BackendUtility::workspaceOL('sys_template', $row);
            if (is_array($row)) {
                $templateRows[] = $row;
            }
        }
        return $templateRows;
    }

    /**
     * Get a single sys_template record attached to a single page.
     * If multiple template records are on this page, the first (order by sorting)
     * record will be returned, unless a specific template uid is specified via $templateUid
     *
     * @param int $pageId The pid to select sys_template records from
     * @param int $templateUid Optional template uid
     * @return array<string,mixed>|false Returns the template record or false if none was found
     */
    protected function getFirstTemplateRecordOnPage(int $pageId, int $templateUid = 0): array|false
    {
        if (empty($pageId)) {
            return false;
        }
        $queryBuilder = $this->getTemplateQueryBuilder($pageId)->setMaxResults(1);
        if ($templateUid) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($templateUid, \PDO::PARAM_INT))
            );
        }
        $row = $queryBuilder->executeQuery()->fetchAssociative();
        BackendUtility::workspaceOL('sys_template', $row);
        return $row;
    }

    /**
     * Helper method to prepare the query builder for getting sys_template records from a given pid.
     */
    protected function getTemplateQueryBuilder(int $pid): QueryBuilder
    {
        $backendUser = $this->getBackendUser();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_template');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $backendUser->workspace));
        return $queryBuilder->select('*')
            ->from('sys_template')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT))
            )
            ->orderBy($GLOBALS['TCA']['sys_template']['ctrl']['sortby']);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
