<?php

namespace App\DTOs;

use App\Enums\BotModeEnum;
use Illuminate\Support\Facades\App;

readonly class HomepageInfoDTO
{
    public string $officialTGLink;

    public string $webAppLoginLink;

    public string $eventLink;

    public string $agentLink;

    public string $withdrawalLink;

    public string $customerServiceLink;

    public string $walletLink;

    public string $siteLaunchLink;

    public string $vipLink;

    public string $historyLink;

    public string $shareLink;

    public string $shareButtonName;

    public string $startMessage;

    public string $restartMessage;

    public CoverMediaDTO $startCoverMedia;

    public CoverMediaDTO $restartCoverMedia;

    public CoverMediaDTO $vipCoverMedia;

    public int $mainButtonCsPreLogin;

    public int $mainButtonOfficial;

    public int $mainButtonWallet;

    public int $mainButtonVip;

    public int $mainButtonEvent;

    public int $mainButtonInvite;

    public int $mainButtonCheckin;

    public int $mainButtonMission;

    public int $mainButtonHistory;

    public int $mainButtonCsPostLogin;

    public int $mainButtonHomepage;

    public int $mainButtonShare;

    public string $defaultLanguage;

    public array $linkButtonsConfig;

    public BotModeEnum $botMode;

    public function __construct(array $homepageData)
    {
        $this->eventLink = $homepageData['event_link'] . '&lang=' . App::getLocale();
        $this->agentLink = $homepageData['agent_link'] . '&lang=' . App::getLocale();
        $this->withdrawalLink = $homepageData['withdrawal_link'] . '&lang=' . App::getLocale();
        $this->walletLink = $homepageData['wallet_link'] . '&lang=' . App::getLocale();
        $this->siteLaunchLink = $homepageData['launch_link'] . '&lang=' . App::getLocale();
        $this->vipLink = $homepageData['vip_link'] . '&lang=' . App::getLocale();
        $this->historyLink = $homepageData['history_link'] . '&lang=' . App::getLocale();

        $this->startMessage = $homepageData['start_message'] ?? '';
        $this->startCoverMedia = new CoverMediaDTO(
            $homepageData['start_cover_media']['type'] ?? '',
            $homepageData['start_cover_media']['url'] ?? ''
        );

        $this->restartMessage = $homepageData['restart_message'] ?? '';
        $this->restartCoverMedia = new CoverMediaDTO(
            $homepageData['restart_cover_media']['type'] ?? '',
            $homepageData['restart_cover_media']['url'] ?? ''
        );

        $this->vipCoverMedia = new CoverMediaDTO(
            $homepageData['vip_cover_media']['type'] ?? '',
            $homepageData['vip_cover_media']['url'] ?? ''
        );

        $this->mainButtonCsPreLogin = $homepageData['main_button_cs_pre_login'] ?? 0;
        $this->mainButtonOfficial = $homepageData['main_button_official'] ?? 0;
        $this->mainButtonWallet = $homepageData['main_button_wallet'] ?? 0;
        $this->mainButtonVip = $homepageData['main_button_vip'] ?? 0;
        $this->mainButtonEvent = $homepageData['main_button_event'] ?? 0;
        $this->mainButtonInvite = $homepageData['main_button_invite'] ?? 0;
        $this->mainButtonCheckin = $homepageData['main_button_checkin'] ?? 0;
        $this->mainButtonMission = $homepageData['main_button_mission'] ?? 0;
        $this->mainButtonHistory = $homepageData['main_button_history'] ?? 0;
        $this->mainButtonCsPostLogin = $homepageData['main_button_cs_post_login'] ?? 0;
        $this->mainButtonHomepage = $homepageData['main_button_homepage'] ?? 0;
        $this->mainButtonShare = $homepageData['main_button_share'] ?? 0;

        $this->webAppLoginLink = $homepageData['webapp_login_link'] ?? '';
        $this->shareLink = $homepageData['share_link'] ?? '';
        $this->shareButtonName = $homepageData['share_button_name'] ?? '';

        $this->linkButtonsConfig = $homepageData['link_buttons_config'] ?? [];

        $this->botMode = BotModeEnum::from($homepageData['bot_mode'] ?? BotModeEnum::FIRST->value);

        // 預設語言
        $defaultLanguage = $homepageData['default_language'] ?? 'en';

        // 將 API_Frontend 專案的語言轉換為此專案的語言
        if ($defaultLanguage == 'en') {
            $defaultLanguage = 'en-us';
        } elseif ($defaultLanguage == 'my-mm') {
            $defaultLanguage = 'mm-my';
        }
        $this->defaultLanguage = $defaultLanguage;
    }
}
