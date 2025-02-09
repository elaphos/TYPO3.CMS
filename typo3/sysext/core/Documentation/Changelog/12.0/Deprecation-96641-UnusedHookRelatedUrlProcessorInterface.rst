.. include:: /Includes.rst.txt

===============================================================
Deprecation: #96641 - Unused Hook related UrlProcessorInterface
===============================================================

See :issue:`96641`

Description
===========

The hook :php:`$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['urlProcessing']['urlProcessors']`
required hook implementations to implement :php:`UrlProcessorInterface`.

Since the mentioned hook is :doc:`removed <../12.0/Breaking-96641-TypoLinkRelatedHooksRemoved>`,
the interface is not in use anymore and has been marked as deprecated.


Impact
======

The extension scanner will now notify any extension, which might still use
the PHP interface.


Affected Installations
======================

TYPO3 installations using the PHP interface in custom extension code.


Migration
=========

The PHP interface is still available for TYPO3 v12.x, so extensions can
provide a version which is compatible with TYPO3 v11 (using the hook)
and TYPO3 v12.x (using the new
:doc:`PSR-14 Event <../12.0/Feature-96641-NewPSR-14EventForModifyingLinks>`),
at the same time.

Remove any usage of the PHP interface and use the new PSR-14
Event to avoid any further problems in TYPO3 v13+.

.. index:: Frontend, FullyScanned, ext:frontend
