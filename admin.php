<?php
/**
 * Delete unnecessary languages -> administration function
 *
 * @license	GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author	Taggic <taggic@t-online.de>
 * @author	Ivor Barhansky <w@lopar.space>
 */


use dokuwiki\Extension\AdminPlugin;

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

/** Implicit data type:
 *
 * ^Lang is an array that looks like the following
 * { "core": [ $lang... ],
 *      "templates": [ $tpl_name: [ $lang... ], ... ],
 *      "plugins": [ $plugin_name: [ $lang... ], ... ]
 * }
 *     where $lang is a DokuWiki language code
 *           $tpl_name is the template name
 *           $plugin_name is the plugin name
 *  The $lang arrays are zero-indexed
 */

/** CSS Classes:
 *
 * ul.languages is an inline list of language codes
 *   if li.active is set, the text will be highlighted
 *   if li.enabled is set, the text is normal,
 *       otherwise it's red and striked-out
 * .module is set on text that represent module names: template names,
 *     plugin names and "dokuwiki"
 *
 * #langshortlist is the list of language with checkboxes
 * #langlonglist is the list of list of languages available for each module
 * .langdelete__text is the class set on the section wrapper around all the text
 */

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_langdelete extends AdminPlugin
{

    /** Fallback language */
    private const DEFAULT_LANG = 'en';
    
    private bool $submit;
	
    private bool $securityTokenIsValid;
	
    private array $languagesList;
	
    private array $uniqueLanguageList;
	
    /** What languages do we keep? */
    private array $keepLanguagesList;
	
    private array $languagesForDeletion;
	
    private array $languageListShorts;
	
    /** Is it a test run without data deletion? */
    private bool $isDryRun;
	
    private string $nolang = '';
	
    private bool $discrepancy = false;

    /** return sort order for position in admin menu */
    public function getMenuSort(): int
    {
        return 20;
    }

    /** Called when dispatching the DokuWiki action; */
    public function handle(): void
    {
        global $conf;

        /* What languages do we keep ? */
        $this->keepLanguagesList[] = self::DEFAULT_LANG; // add 'en', the fallback
        $this->keepLanguagesList[] = $conf['lang'];      // add current lang
        
        $this->submit = isset($_REQUEST['submit']);

        /* Check security token */
        if ($this->submit) {
            $this->securityTokenIsValid = true;
            if (!checkSecurityToken()) {
                $this->securityTokenIsValid = false;
                return;
            }
        }

        /* Set DokuWiki language info */
        $this->languagesList = $this->list_languages();

        // $u_langs is in alphabetical (?) order because directory listing
        $this->uniqueLanguageList = $this->lang_unique($this->languagesList);

        /* Grab form data */
        if ($this->submit) {
            $this->isDryRun = $_REQUEST['dryrun'];
            $langStr = $_REQUEST['langdelete_w'];

            /* Add form data to languages to keep */
            if (strlen($langStr) > 0) {
                $this->keepLanguagesList = array_merge($this->keepLanguagesList, explode(',', $langStr));
            }
        } else {
            // Keep every language on first run
            $this->keepLanguagesList = $this->uniqueLanguageList;
        }

        $this->keepLanguagesList = array_values(array_filter(array_unique($this->keepLanguagesList)));

        /* Does the language we want to keep actually exist ? */
        $noLangs = array_diff($this->keepLanguagesList, $this->uniqueLanguageList);
        if ($noLangs) {
            $this->nolang = implode(",", $noLangs);
        }

        /* Prepare data for deletion */
        if ($this->submit) {
            $this->languagesForDeletion = $this->_filter_out_lang($this->languagesList, $this->keepLanguagesList);
        }

        /* What do the checkboxes say ? */
        if ($this->submit) {
            /* Grab checkboxes */
            $this->languageListShorts = array_keys($_REQUEST['shortlist']);

            /* Prevent discrepancy between shortlist and text form */
            if (array_diff($this->keepLanguagesList, $this->languageListShorts)
                || array_diff($this->languageListShorts, $this->keepLanguagesList)) {
                $this->discrepancy = true;
            }
        } else {
            // Keep every language on first run
            $this->languageListShorts = $this->uniqueLanguageList;
        }
    }

    /** Returns the available languages for each module
     * (core, template or plugin)
     *
     * Signature: () => ^Lang
     */
    private function list_languages(): array
    {
        // See https://www.dokuwiki.org/devel:localization

        /* Returns the subfolders of $dir as an array */
        $dir_subfolders = function ($dir) {
            $sub = scandir($dir);
            if (!$sub) {
                return [];
            }
            return array_filter($sub, function ($e) use ($dir) {
                return is_dir("$dir/$e")
                    && !in_array($e, array('.', '..'));
            });
        };

        /* Return an array of template names */
        $list_templates = function () use ($dir_subfolders) {
            return $dir_subfolders (DOKU_INC . "lib/tpl");
        };

        /* Return an array of languages available for the module
         * (core, template or plugin) given its $root directory */
        $list_langs = function ($root) use ($dir_subfolders) {
            $dir = "$root/lang";
            if (!is_dir($dir)) {
                return [];
            }
            return $dir_subfolders ($dir);
        };

        /* Get templates and plugins names */
        global $plugin_controller;
        $plugins = $plugin_controller->getList();
        $templates = $list_templates();

        return [
            "core" => $list_langs (DOKU_INC . "inc"),
            "templates" => array_combine($templates,
                array_map($list_langs,
                    array_prefix($templates, DOKU_INC . "lib/tpl/"))),
            "plugins" => array_combine($plugins,
                array_map($list_langs,
                    array_prefix($plugins, DOKU_PLUGIN)))
        ];
    }

    /** Return an array of the languages in $l
     *
     * Signature: ^Lang => Array */
    private function lang_unique($l): array
    {
        $count = [];
        foreach ($l['core'] as $lang) {
            $count[$lang]++;
        }
        foreach ($l['templates'] as $arr) {
            foreach ($arr as $lang) {
                $count[$lang]++;
            }
        }
        foreach ($l['plugins'] as $arr) {
            foreach ($arr as $lang) {
                $count[$lang]++;
            }
        }

        return array_keys($count);
    }

    /** Remove $lang_keep from the module languages $e
     *
     * Signature: ^Lang, Array => ^Lang */
    private function _filter_out_lang($e, $lang_keep): array
    {
        // Recursive function with cases being an array of arrays, or an array
        if (count($e) > 0 && is_array(array_values($e)[0])) {
            $out = [];
            foreach ($e as $k => $elt) {
                $out[$k] = $this->_filter_out_lang($elt, $lang_keep);
            }
            return $out;

        } else {
            return array_filter($e, function ($v) use ($lang_keep) {
                return !in_array($v, $lang_keep);
            });
        }
    }

    /**
     * langdelete Output function
     *
     * Prints a table with all found language folders.
     * HTML and data processing are done here at the same time
     *
     * @author  Taggic <taggic@t-online.de>
     */
    public function html(): void
    {
        // langdelete__intro
        echo $this->locale_xhtml('intro');

        // input anchor
        echo '<a id="langdelete_inputbox"></a>' . NL;
        echo $this->locale_xhtml('guide');
        // input form
        $this->_html_form();

        /* Switch on form submission state */
        if (!$this->submit) {
            /* Show available languages */
            echo '<section class="langdelete__text">';
            echo $this->getLang('available_langs');
            $this->print_shortlist();
            $this->html_print_langs($this->languagesList);
            echo '</section>';

        } else {
            /* Process form */

            /* Check token */
            if (!$this->securityTokenIsValid) {
                echo "<p>Invalid security token</p>";
                return;
            }

            if ($this->discrepancy) {
                msg($this->getLang('discrepancy_warn'), 2);
            }
            if ($this->nolang) {
                msg($this->getLang('nolang') . $this->nolang, 2);
            }

            echo '<h2>' . $this->getLang('h2_output') . '</h2>' . NL;

            if ($this->isDryRun) {
                /* Display what will be deleted */
                msg($this->getLang('langdelete_willmsg'), 2);
                echo '<section class="langdelete__text">';
                echo $this->getLang('available_langs');
                $this->print_shortlist();
                $this->html_print_langs($this->languagesList, $this->keepLanguagesList);
                echo '</section>';

                msg($this->getLang('langdelete_attention'), 2);
                echo '<a href="#langdelete_inputbox">' . $this->getLang('backto_inputbox') . '</a>' . NL;

            } else {
                /* Delete and report what was deleted */
                msg($this->getLang('langdelete_delmsg'));

                echo '<section class="langdelete__text">';
                $this->html_print_langs($this->languagesForDeletion);
                echo '</section>';

                echo '<pre>';
                $this->remove_langs($this->languagesForDeletion);
                echo '</pre>';
            }
        }
    }

    /**
     * Display the form with input control to let the user specify,
     * which languages to be kept beside en
     *
     * @author  Taggic <taggic@t-online.de>
     */
    private function _html_form(): void
    {
        global $ID, $conf;

        echo '<form id="langdelete__form" action="' . wl($ID) . '" method="post">';
        echo '<input type="hidden" name="do" value="admin" />' . NL;
        echo '<input type="hidden" name="page" value="' . $this->getPluginName() . '" />' . NL;
        formSecurityToken();

        echo '<fieldset class="langdelete__fieldset"><legend>' . $this->getLang('i_legend') . '</legend>' . NL;

        echo '<label class="formTitle">' . $this->getLang('i_using') . ':</label>';
        echo '<div class="box">' . $conf['lang'] . '</div>' . NL;

        echo '<label class="formTitle" for="langdelete_w">' . $this->getLang('i_shouldkeep') . ':</label>';
        echo '<input type="text" name="langdelete_w" class="edit" value="' . hsc(implode(',', $this->keepLanguagesList)) . '" />' . NL;

        echo '<label class="formTitle" for="option">' . $this->getLang('i_runoption') . ':</label>';
        echo '<div class="box">' . NL;
        echo '<input type="checkbox" name="dryrun" checked="checked" /> ';
        echo '<label for="dryrun">' . $this->getLang('i_dryrun') . '</label>' . NL;
        echo '</div>' . NL;

        echo '<button name="submit">' . $this->getLang('btn_start') . '</button>' . NL;

        echo '</fieldset>' . NL;
        echo '</form>' . NL;
    }

    /** Print the language shortlist and cross-out those not in $keep */
    private function print_shortlist(): void
    {
        echo '<ul id="langshortlist" class="languages">';

        # As the disabled input won't POST
        echo '<input type="hidden" name="shortlist[' . self::DEFAULT_LANG . ']"'
            . ' form="langdelete__form" />';

        foreach ($this->uniqueLanguageList as $l) {
            $checked = in_array($l, $this->languageListShorts) || $l == self::DEFAULT_LANG;

            echo '<li' . ($checked ? ' class="enabled"' : '') . '>';

            echo '<input type="checkbox" id="shortlang-' . $l . '"'
                . ' name="shortlist[' . $l . ']"'
                . ' form="langdelete__form"'
                . ($checked ? ' checked' : '')
                . ($l == self::DEFAULT_LANG ? ' disabled' : '')
                . ' />';

            echo '<label for="shortlang-' . $l . '">';
            if ($checked) {
                echo $l;
            } else {
                echo '<del>' . $l . '</del>';
            }
            echo '</label>';

            echo '</li>';
        }
        echo '</ul>';
    }

    /** Display the languages in $langs for each module as a HTML list;
     * Cross-out those not in $keep
     *
     * Signature: ^Lang, Array => () */
    private function html_print_langs($langs, $keep = null): void
    {
        /* Print language list, $langs being an array;
         * Cross out those not in $keep */
        $print_lang_li = function ($langs) use ($keep) {
            echo '<ul class="languages">';
            foreach ($langs as $val) {
                // If $keep is null, we keep everything
                $enabled = is_null($keep) || in_array($val, $keep);

                echo '<li val="' . $val . '"'
                    . ($enabled ? ' class="enabled"' : '')
                    . '>';
                if ($enabled) {
                    echo $val;
                } else {
                    echo '<del>' . $val . '</del>';
                }
                echo '</li>';
            }
            echo '</ul>';
        };


        echo '<ul id="langlonglist">';

        // Core
        echo '<li><span class="module">' . $this->getLang('dokuwiki_core') . '</span>';
        $print_lang_li ($langs['core']);
        echo '</li>';

        // Templates
        echo '<li>' . $this->getLang('templates');
        echo '<ul>';
        foreach ($langs['templates'] as $name => $l) {
            echo '<li><span class="module">' . $name . ':</span>';
            $print_lang_li ($l);
            echo '</li>';
        }
        echo '</ul>';
        echo '</li>';

        // Plugins
        echo '<li>' . $this->getLang('plugins');
        echo '<ul>';
        foreach ($langs['plugins'] as $name => $l) {
            echo '<li><span class="module">' . $name . ':</span>';
            $print_lang_li ($l);
            echo '</li>';
        }
        echo '</ul>';
        echo '</li>';

        echo '</ul>';
    }

    /** Delete the languages from the modules as specified by $langs
     *
     * Signature: ^Lang => () */
    private function remove_langs($langs): void
    {
        foreach ($langs['core'] as $l) {
            $this->rrm(DOKU_INC . "inc/lang/$l");
        }

        foreach ($langs['templates'] as $tpl => $arr) {
            foreach ($arr as $l) {
                $this->rrm(DOKU_INC . "lib/tpl/$tpl/lang/$l");
            }
        }

        foreach ($langs['plugins'] as $plug => $arr) {
            foreach ($arr as $l) {
                $this->rrm(DOKU_INC . "lib/plugins/$plug/lang/$l");
            }
        }
    }

    /** Recursive file removal of $path with reporting */
    private function rrm($path): void
    {
        if (is_dir($path)) {
            $objects = scandir($path);
            foreach ($objects as $object) {
                if (!in_array($object, array('.', '..'))) {
                    $this->rrm("$path/$object");
                }
            }
            $sucess = @rmdir($path);
            if (!$sucess) {
                echo "Failed to delete $path/" . NL;
            } else {
                echo "Delete $path" . NL;
            }
        } else {
            $sucess = @unlink($path);
            if (!$sucess) {
                echo "Failed to delete $path" . NL;
            } else {
                echo "Delete $path" . NL;
            }
        }
    }
}

/** Returns an array with each element of $arr prefixed with $prefix */
function array_prefix($arr, $prefix): array
{
    return array_map(
        function ($p) use ($prefix) {
            return $prefix . $p;
        },
        $arr);
}
