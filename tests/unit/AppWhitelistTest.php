<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Guests\Test\Unit;

use OCA\Guests\AppWhitelist;
use OCA\Guests\Config;
use OCA\Guests\GuestManager;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AppWhitelistTest extends TestCase {
	private Config&MockObject $config;

	private GuestManager&MockObject $guestManager;

	private IL10N&MockObject $l10n;

	private IAppManager&MockObject $appManager;

	private IURLGenerator&MockObject $urlGenerator;

	private ?AppWhitelist $appWhitelist = null;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(Config::class);
		$this->guestManager = $this->createMock(GuestManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getBaseUrl')
			->willReturn('');

		$this->appWhitelist = new AppWhitelist(
			$this->urlGenerator,
			$this->config,
			$this->guestManager,
			$this->l10n,
			$this->appManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIsUrlAllowed(): void {
		$this->config->method('getAppWhitelist')
			->willReturn(['foo', 'bar']);
		$this->config->method('useWhitelist')
			->willReturn(true);
		$this->guestManager->method('isGuest')
			->willReturn(true);
		$this->appManager->method('cleanAppId')
			->willReturnCallback(fn (string $appId) => $appId);
		$user = $this->createStub(IUser::class);

		$this->assertFalse($this->appWhitelist->isUrlAllowed($user, '/apps/news/...'));
		$this->assertTrue($this->appWhitelist->isUrlAllowed($user, '/apps/foo/...'));
	}

	public function testIsUrlAllowedNoWhitelist(): void {
		$this->config->method('getAppWhitelist')
			->willReturn(['foo', 'bar']);
		$this->config->method('useWhitelist')
			->willReturn(false);
		$this->guestManager->method('isGuest')
			->willReturn(true);
		$this->appManager->method('cleanAppId')
			->willReturnCallback(fn (string $appId) => $appId);
		$user = $this->createStub(IUser::class);

		$this->assertTrue($this->appWhitelist->isUrlAllowed($user, '/apps/news/...'));
		$this->assertTrue($this->appWhitelist->isUrlAllowed($user, '/apps/foo/...'));
	}

	/**
	 * getRequestedApp() returns an empty string for urls that carry no
	 * resolvable app id, so it must never be treated as whitelisted.
	 */
	public function testEmptyAppIdIsNotWhitelisted(): void {
		$this->config->method('getAppWhitelist')
			->willReturn(['foo', 'bar']);

		$this->assertFalse($this->appWhitelist->isAppWhitelisted(''));
	}

	/**
	 * Two-factor authentication has to keep working for guests, so an app that
	 * declares a provider in its info.xml is allowed without the administrator
	 * having to whitelist it by hand.
	 */
	public function testTwoFactorProviderAppIsWhitelisted(): void {
		$this->config->method('getAppWhitelist')
			->willReturn(['foo', 'bar']);
		$this->appManager->method('getAppInfo')
			->willReturnCallback(fn (string $appId): ?array => match ($appId) {
				'twofactor_email' => ['two-factor-providers' => ['OCA\TwoFactorEmail\Provider\EmailProvider']],
				default => null,
			});

		$this->assertTrue($this->appWhitelist->isAppWhitelisted('twofactor_email'));
	}

	/**
	 * The providers that used to be hardcoded are covered by the info.xml
	 * lookup now, so dropping them from WHITELIST_ALWAYS must not lock them out.
	 */
	public function testPreviouslyHardcodedTwoFactorAppsStayWhitelisted(): void {
		$this->config->method('getAppWhitelist')
			->willReturn([]);
		$this->appManager->method('getAppInfo')
			->willReturnCallback(fn (string $appId): ?array => str_starts_with($appId, 'twofactor_')
				? ['two-factor-providers' => ['OCA\Some\Provider']]
				: null);

		foreach (['twofactor_totp', 'twofactor_webauthn', 'twofactor_nextcloud_notification'] as $appId) {
			$this->assertTrue($this->appWhitelist->isAppWhitelisted($appId), $appId);
		}
	}

	/**
	 * twofactor_gateway registers its providers in the app bootstrap, so there
	 * is no info.xml declaration to find and it has to stay listed by name.
	 */
	public function testBootstrapRegisteredTwoFactorAppIsWhitelisted(): void {
		$this->config->method('getAppWhitelist')
			->willReturn([]);
		$this->appManager->method('getAppInfo')
			->willReturn(null);

		$this->assertTrue($this->appWhitelist->isAppWhitelisted('twofactor_gateway'));
	}

	public function testAppWithoutTwoFactorProviderIsNotWhitelisted(): void {
		$this->config->method('getAppWhitelist')
			->willReturn([]);
		$this->appManager->method('getAppInfo')
			->willReturn(['two-factor-providers' => []]);

		$this->assertFalse($this->appWhitelist->isAppWhitelisted('news'));
	}

	/**
	 * Two-factor provider apps are always allowed, so offering them as a
	 * whitelist toggle in the settings would be a no-op.
	 */
	public function testGetWhitelistAbleAppsExcludesTwoFactorProviderApps(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn(['files', 'news', 'twofactor_email', 'twofactor_gateway']);
		$this->appManager->method('getAppInfo')
			->willReturnCallback(fn (string $appId): ?array => match ($appId) {
				'twofactor_email' => ['two-factor-providers' => ['OCA\TwoFactorEmail\Provider\EmailProvider']],
				default => null,
			});

		$this->assertEquals(['news'], $this->appWhitelist->getWhitelistAbleApps());
	}
}
