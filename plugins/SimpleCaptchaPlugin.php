<?php
/**
 * Simple Captcha Plugin for phplist.
 *
 * This file is a part of SimpleCaptchaPlugin.
 *
 * @category  phplist
 *
 * @author    Duncan Cameron
 * @copyright 2025 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

use SimpleCaptcha\Builder;

use function phpList\plugin\Common\publicUrl;

class SimpleCaptchaPlugin extends phplistPlugin
{
    const VERSION_FILE = 'version.txt';

    /*
     *  Inherited variables
     */
    public $name = 'Simple Captcha Plugin';
    public $description = 'Creates a captcha field for subscription forms';
    public $documentationUrl = 'https://resources.phplist.com/plugin/simplecaptcha';
    public $authors = 'Duncan Cameron';
    public $publicPages = ['refresh', 'show'];
    public $settings = [
        'simple_captcha_prompt' => [
            'value' => 'Please enter the text in the CAPTCHA image',
            'description' => 'Prompt for the CAPTCHA field',
            'type' => 'text',
            'allowempty' => 0,
            'category' => 'Simple Captcha',
        ],
        'simple_captcha_message' => [
            'value' => 'The CAPTCHA value that you entered was incorrect',
            'description' => 'Message to be displayed when entered CAPTCHA is incorrect',
            'type' => 'text',
            'allowempty' => 0,
            'category' => 'Simple Captcha',
        ],
        'simple_captcha_eventlog' => [
            'description' => 'Whether to log event for each rejected captcha and each rejected subscription',
            'type' => 'boolean',
            'value' => '1',
            'allowempty' => true,
            'category' => 'Simple Captcha',
        ],
        'simple_captcha_copyadmin' => [
            'description' => 'Whether to send an email to the admin for each rejected captcha and each rejected subscription',
            'type' => 'boolean',
            'value' => '0',
            'allowempty' => true,
            'category' => 'Simple Captcha',
        ],
    ];

    public function __construct()
    {
        $this->coderoot = __DIR__ . '/' . __CLASS__ . '/';
        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '';
        parent::__construct();
    }

    /**
     * Provide the dependencies for enabling this plugin.
     *
     * @return array
     */
    public function dependencyCheck(): array
    {
        return [
            'PHP version 8 or greater' => version_compare(PHP_VERSION, '8') > 0,
            'GD extension installed' => extension_loaded('gd'),
            'Common Plugin must be enabled' => phpListPlugin::isEnabled('CommonPlugin'),
        ];
    }

    public function adminmenu()
    {
        return [];
    }

    /**
     * Provide the captcha html to be included in a subscription page.
     *
     * @param array $pageData subscribe page fields
     * @param int   $userID   user id
     *
     * @return string
     */
    public function displaySubscriptionChoice($pageData, $userID = 0): string
    {
        if ($_GET['p'] != 'subscribe' || empty($pageData['simple_captcha_include'])) {
            return '';
        }
        $captchaImageUrl = publicUrl(['p' => 'show', 'pi' => __CLASS__]);
        $onClick = <<<END
        document.getElementById('simple_captcha_image').src = '$captchaImageUrl&random=' + Math.random(); this.blur(); return false
END;
        $refreshImage = sprintf(
            '<img src="%s"/>',
            htmlspecialchars(publicUrl(['p' => 'refresh', 'pi' => __CLASS__]))
        );
        $refreshLink = sprintf('<a href="" onClick="%s">%s</a>', htmlspecialchars($onClick), $refreshImage);
        $format = <<<END
        <div style="clear: both"></div>
        <img id="simple_captcha_image" src="%s" />
        %s
        <div style="clear: both"></div>
        <label for="simple_captcha_phrase">%s</label>
        <input type="text" name="simple_captcha_phrase" id="simple_captcha_phrase" autocomplete="off" required>
END;
        $html = sprintf($format, $captchaImageUrl, $refreshLink, htmlspecialchars(getConfig('simple_captcha_prompt')));

        return $html;
    }

    /**
     * Provide additional validation when a subscribe page has been submitted.
     *
     * @param array $pageData subscribe page fields
     *
     * @return string an error message to be displayed or an empty string
     *                when validation is successful
     */
    public function validateSubscriptionPage($pageData): string
    {
        if (empty($_POST)
            || ($_GET['p'] == 'asubscribe' && !empty($pageData['simple_captcha_not_asubscribe']))
            || $_GET['p'] == 'preferences'
            || !isset($_POST['email'])
        ) {
            return '';
        }
        $result = $this->validateCaptcha($_POST['email'], $_POST['simple_captcha_phrase']);
        unset($_SESSION['simple_captcha_phrase']);

        return $result;
    }

    /**
     * Provide html for the captcha options when editing a subscribe page.
     *
     * @param array $pageData subscribe page fields
     *
     * @return string additional html
     */
    public function displaySubscribepageEdit($pageData): string
    {
        $include = $pageData['simple_captcha_include'] ?? false;
        $notAsubscribe = $pageData['simple_captcha_not_asubscribe'] ?? true;
        $html =
            CHtml::label(s('Include captcha in the subscribe page'), 'simple_captcha_include')
            . CHtml::checkBox('simple_captcha_include', $include, array('value' => 1, 'uncheckValue' => 0))
            . CHtml::label(s('Do not validate captcha for asubscribe'), 'simple_captcha_not_asubscribe')
            . CHtml::checkBox('simple_captcha_not_asubscribe', $notAsubscribe, array('value' => 1, 'uncheckValue' => 0));

        return $html;
    }

    /**
     * Save the captcha settings.
     *
     * @param int $id subscribe page id
     */
    public function processSubscribePageEdit($id): void
    {
        global $tables;

        Sql_Query(
            sprintf('
                REPLACE INTO %s
                (id, name, data)
                VALUES
                (%d, "simple_captcha_include", "%s"),
                (%d, "simple_captcha_not_asubscribe", "%s")
                ',
                $tables['subscribepage_data'],
                $id,
                $_POST['simple_captcha_include'],
                $id,
                $_POST['simple_captcha_not_asubscribe']
            )
        );
    }

    private function validateCaptcha($email, $phrase): string
    {
        $captcha = Builder::create();

        if (isset($_SESSION['simple_captcha_phrase']) && $captcha->compare($_SESSION['simple_captcha_phrase'], $phrase)) {
            return '';
        }
        $text = "captcha verification failure: $email";

        if (getConfig('simple_captcha_eventlog')) {
            logEvent($text);
        }

        if (getConfig('simple_captcha_copyadmin')) {
            $body = <<<END
A subscription attempt has been rejected by the Simple Captcha plugin.

$text
END;
            sendAdminCopy('subscription rejected by Simple Captcha', $body);
        }

        return getConfig('simple_captcha_message');
    }
}
