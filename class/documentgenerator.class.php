<?php
/* Copyright (C) 2024
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

/**
 * Class MultiDocGenerator
 * Generates documents from templates using Dolibarr's variable substitution
 */
class MultiDocGenerator
{
    public $db;
    public $error = '';
    public $errors = array();

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate document from template
     *
     * @param MultiDocTemplate $template Template object
     * @param CommonObject $object Object to use for substitution (Societe or Contact)
     * @param string $object_type Object type (thirdparty or contact)
     * @param User $user User generating the document
     * @param string $tag_filter Tag/category filter for folder organization
     * @return int >0 if OK, <0 if KO
     */
    public function generate($template, $object, $object_type, $user, $tag_filter = '')
    {
        global $conf, $langs, $mysoc;

        $error = 0;

        // Check template file exists
        if (!file_exists($template->filepath)) {
            $this->error = $langs->trans('ErrorTemplateFileNotFound');
            return -1;
        }

        // Use template's tag for folder organization if no tag_filter provided
        $folder_tag = !empty($tag_filter) ? $tag_filter : (!empty($template->tag) ? $template->tag : '');

        // Create archive directory
        $archive_dir = MultiDocArchive::getArchiveDir($object_type, $object->id, $folder_tag);
        if (!is_dir($archive_dir)) {
            if (dol_mkdir($archive_dir) < 0) {
                $this->error = $langs->trans('ErrorCanNotCreateDir', $archive_dir);
                return -2;
            }
        }

        // Generate output filename
        $ext = pathinfo($template->filename, PATHINFO_EXTENSION);
        $output_filename = $this->generateOutputFilename($template, $object, $object_type, $ext);
        $output_filepath = $archive_dir.'/'.$output_filename;

        // Process based on file type
        $result = 0;
        switch (strtolower($ext)) {
            case 'odt':
                $result = $this->processODT($template->filepath, $output_filepath, $object, $object_type);
                break;
            case 'ods':
                $result = $this->processODS($template->filepath, $output_filepath, $object, $object_type);
                break;
            default:
                // For other formats, just copy with basic text substitution if possible
                $result = $this->processCopy($template->filepath, $output_filepath, $object, $object_type);
                break;
        }

        if ($result < 0) {
            return $result;
        }

        // Create archive record
        require_once __DIR__.'/archive.class.php';
        $archive = new MultiDocArchive($this->db);
        $archive->ref = MultiDocArchive::generateRef($object_type, $object->id);
        $archive->fk_template = $template->id;
        $archive->object_type = $object_type;
        $archive->object_id = $object->id;
        $archive->filename = $output_filename;
        $archive->filepath = $output_filepath;
        $archive->filetype = strtolower($ext);
        $archive->filesize = filesize($output_filepath);
        $archive->tag_filter = $folder_tag;

        $result = $archive->create($user);

        if ($result < 0) {
            // Delete generated file if DB insert failed
            dol_delete_file($output_filepath);
            $this->error = $archive->error;
            return -3;
        }

        return $archive->id;
    }

    /**
     * Process ODT template with variable substitution
     *
     * @param string $template_path Path to template
     * @param string $output_path Path for output file
     * @param CommonObject $object Object for substitution
     * @param string $object_type Object type
     * @return int >0 if OK, <0 if KO
     */
    protected function processODT($template_path, $output_path, $object, $object_type)
    {
        global $conf, $langs, $mysoc;

        // Check if ODT processing library exists
        if (!class_exists('Odf')) {
            // Try to include Dolibarr's ODFPhp library
            $odfphp_path = DOL_DOCUMENT_ROOT.'/includes/odtphp/odf.php';
            if (file_exists($odfphp_path)) {
                require_once $odfphp_path;
            } else {
                // Fallback: simple copy
                return $this->processCopy($template_path, $output_path, $object, $object_type);
            }
        }

        try {
            // Determine temp directory - use module's temp dir or fallback to main temp
            $temp_dir = '';
            if (!empty($conf->multidoctemplate->dir_temp)) {
                $temp_dir = $conf->multidoctemplate->dir_temp;
            } else {
                // Fallback to Dolibarr's main temp directory
                $temp_dir = DOL_DATA_ROOT.'/multidoctemplate/temp';
            }

            // Create temp directory if it doesn't exist
            if (!is_dir($temp_dir)) {
                if (dol_mkdir($temp_dir) < 0) {
                    $this->error = $langs->trans('ErrorCanNotCreateDir', $temp_dir);
                    return -11;
                }
            }

            // Load ODT template
            $odfHandler = new Odf(
                $template_path,
                array(
                    'PATH_TO_TMP' => $temp_dir,
                    'ZIP_PROXY' => 'PclZipProxy',
                    'DELIMITER_LEFT' => '{',
                    'DELIMITER_RIGHT' => '}'
                )
            );

            // Get substitution array
            $substitutions = $this->getSubstitutionArray($object, $object_type);

            // Apply substitutions
            foreach ($substitutions as $key => $value) {
                try {
                    $odfHandler->setVars($key, $value, true, 'UTF-8');
                } catch (Exception $e) {
                    // Variable not found in template, continue
                }
            }

            // Save output file
            $odfHandler->saveToDisk($output_path);

            return 1;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return -10;
        }
    }

    /**
     * Process ODS template with variable substitution
     *
     * @param string $template_path Path to template
     * @param string $output_path Path for output file
     * @param CommonObject $object Object for substitution
     * @param string $object_type Object type
     * @return int >0 if OK, <0 if KO
     */
    protected function processODS($template_path, $output_path, $object, $object_type)
    {
        // For ODS, we use similar approach but with spreadsheet handling
        // Fallback to copy for now
        return $this->processCopy($template_path, $output_path, $object, $object_type);
    }

    /**
     * Simple copy with basic text substitution (for formats we can't process)
     *
     * @param string $template_path Path to template
     * @param string $output_path Path for output file
     * @param CommonObject $object Object for substitution
     * @param string $object_type Object type
     * @return int >0 if OK, <0 if KO
     */
    protected function processCopy($template_path, $output_path, $object, $object_type)
    {
        $result = dol_copy($template_path, $output_path, 0, 1);
        return $result ? 1 : -20;
    }

    /**
     * Generate output filename
     *
     * @param MultiDocTemplate $template Template
     * @param CommonObject $object Object
     * @param string $object_type Object type
     * @param string $ext File extension
     * @return string Filename
     */
    protected function generateOutputFilename($template, $object, $object_type, $ext)
    {
        $name_parts = array();

        // Add object ref/name
        if ($object_type == 'thirdparty' && !empty($object->name)) {
            $name_parts[] = dol_sanitizeFileName($object->name);
        } elseif (!empty($object->lastname)) {
            $name_parts[] = dol_sanitizeFileName($object->lastname);
            if (!empty($object->firstname)) {
                $name_parts[] = dol_sanitizeFileName($object->firstname);
            }
        }

        // Add template label
        $name_parts[] = dol_sanitizeFileName($template->label);

        // Add timestamp
        $name_parts[] = date('Ymd_His');

        return implode('_', $name_parts).'.'.$ext;
    }

    /**
     * Get substitution array for object
     * Uses Dolibarr's standard ODT/ODS substitution tags
     * See: https://wiki.dolibarr.org/index.php/Create_an_ODT_or_ODS_document_template
     *
     * @param CommonObject $object Object
     * @param string $object_type Object type
     * @return array Substitution array
     */
    public function getSubstitutionArray($object, $object_type)
    {
        global $conf, $langs, $mysoc, $user;

        $substitutions = array();

        // Use Dolibarr's standard substitution tags (lowercase with underscores)
        // These match the official Dolibarr ODT template documentation

        if ($object_type == 'thirdparty') {
            $substitutions = $this->getThirdpartySubstitutions($object);
        } else {
            $substitutions = $this->getContactSubstitutions($object);
        }

        // Add mycompany substitutions (own company info)
        if (is_object($mysoc)) {
            $substitutions['mycompany_name'] = $mysoc->name;
            $substitutions['mycompany_address'] = $mysoc->address;
            $substitutions['mycompany_zip'] = $mysoc->zip;
            $substitutions['mycompany_town'] = $mysoc->town;
            $substitutions['mycompany_country'] = $mysoc->country;
            $substitutions['mycompany_country_code'] = $mysoc->country_code;
            $substitutions['mycompany_state'] = $mysoc->state;
            $substitutions['mycompany_phone'] = $mysoc->phone;
            $substitutions['mycompany_fax'] = $mysoc->fax;
            $substitutions['mycompany_email'] = $mysoc->email;
            $substitutions['mycompany_web'] = $mysoc->url;
            $substitutions['mycompany_idprof1'] = $mysoc->idprof1;
            $substitutions['mycompany_idprof2'] = $mysoc->idprof2;
            $substitutions['mycompany_idprof3'] = $mysoc->idprof3;
            $substitutions['mycompany_idprof4'] = $mysoc->idprof4;
            $substitutions['mycompany_idprof5'] = $mysoc->idprof5;
            $substitutions['mycompany_idprof6'] = $mysoc->idprof6;
            $substitutions['mycompany_vatnumber'] = $mysoc->tva_intra;
            $substitutions['mycompany_capital'] = $mysoc->capital;
            $substitutions['mycompany_note_public'] = $mysoc->note_public;
            $substitutions['mycompany_default_bank_iban'] = !empty($mysoc->bank_account) ? $mysoc->bank_account->iban : '';
            $substitutions['mycompany_default_bank_bic'] = !empty($mysoc->bank_account) ? $mysoc->bank_account->bic : '';
        }

        // Add user substitutions
        $substitutions['user_login'] = $user->login;
        $substitutions['user_firstname'] = $user->firstname;
        $substitutions['user_lastname'] = $user->lastname;
        $substitutions['user_fullname'] = $user->getFullName($langs);
        $substitutions['user_email'] = $user->email;

        // Add date/time substitutions
        $substitutions['date'] = dol_print_date(dol_now(), 'day');
        $substitutions['datehour'] = dol_print_date(dol_now(), 'dayhour');
        $substitutions['year'] = date('Y');
        $substitutions['month'] = date('m');
        $substitutions['day'] = date('d');

        return $substitutions;
    }

    /**
     * Get thirdparty-specific substitutions
     * Uses Dolibarr's standard {company_xxx} tags
     *
     * @param Societe $object Thirdparty object
     * @return array Substitutions
     */
    protected function getThirdpartySubstitutions($object)
    {
        global $langs;

        $subs = array();

        // Standard Dolibarr company/thirdparty substitution tags
        // As documented in wiki.dolibarr.org ODT template guide
        $subs['company_name'] = $object->name;
        $subs['company_name_alias'] = $object->name_alias;
        $subs['company_address'] = $object->address;
        $subs['company_zip'] = $object->zip;
        $subs['company_town'] = $object->town;
        $subs['company_country'] = $object->country;
        $subs['company_country_code'] = $object->country_code;
        $subs['company_state'] = $object->state;
        $subs['company_state_code'] = $object->state_code;
        $subs['company_phone'] = $object->phone;
        $subs['company_fax'] = $object->fax;
        $subs['company_email'] = $object->email;
        $subs['company_web'] = $object->url;
        $subs['company_barcode'] = $object->barcode;

        // Codes
        $subs['company_customercode'] = $object->code_client;
        $subs['company_suppliercode'] = $object->code_fournisseur;
        $subs['company_customeraccountancycode'] = $object->code_compta;
        $subs['company_supplieraccountancycode'] = $object->code_compta_fournisseur;

        // Legal/Professional IDs
        $subs['company_idprof1'] = $object->idprof1;
        $subs['company_idprof2'] = $object->idprof2;
        $subs['company_idprof3'] = $object->idprof3;
        $subs['company_idprof4'] = $object->idprof4;
        $subs['company_idprof5'] = $object->idprof5;
        $subs['company_idprof6'] = $object->idprof6;
        $subs['company_vatnumber'] = $object->tva_intra;
        $subs['company_capital'] = $object->capital;
        $subs['company_juridicalstatus'] = $object->forme_juridique;
        $subs['company_outstanding_limit'] = $object->outstanding_limit;

        // Notes
        $subs['company_note_public'] = $object->note_public;
        $subs['company_note_private'] = $object->note_private;

        // Bank info (if loaded)
        if (!empty($object->bank_account)) {
            $subs['company_default_bank_iban'] = $object->bank_account->iban;
            $subs['company_default_bank_bic'] = $object->bank_account->bic;
        } else {
            $subs['company_default_bank_iban'] = '';
            $subs['company_default_bank_bic'] = '';
        }

        // Extra fields support: {company_options_xxx}
        if (!empty($object->array_options)) {
            foreach ($object->array_options as $key => $val) {
                // Remove 'options_' prefix for cleaner tag names
                $clean_key = preg_replace('/^options_/', '', $key);
                $subs['company_options_'.$clean_key] = $val;
            }
        }

        return $subs;
    }

    /**
     * Get contact-specific substitutions
     * Uses Dolibarr's standard {contact_xxx} tags
     *
     * @param Contact $object Contact object
     * @return array Substitutions
     */
    protected function getContactSubstitutions($object)
    {
        global $langs;

        $subs = array();

        // Standard Dolibarr contact substitution tags
        $subs['contact_civility'] = $object->civility;
        $subs['contact_firstname'] = $object->firstname;
        $subs['contact_lastname'] = $object->lastname;
        $subs['contact_fullname'] = $object->getFullName($langs);
        $subs['contact_poste'] = $object->poste;

        // Address
        $subs['contact_address'] = $object->address;
        $subs['contact_zip'] = $object->zip;
        $subs['contact_town'] = $object->town;
        $subs['contact_state'] = $object->state;
        $subs['contact_state_code'] = $object->state_code;
        $subs['contact_country'] = $object->country;
        $subs['contact_country_code'] = $object->country_code;

        // Contact info
        $subs['contact_phone'] = $object->phone_pro;
        $subs['contact_phone_pro'] = $object->phone_pro;
        $subs['contact_phone_perso'] = $object->phone_perso;
        $subs['contact_phone_mobile'] = $object->phone_mobile;
        $subs['contact_fax'] = $object->fax;
        $subs['contact_email'] = $object->email;

        // Notes
        $subs['contact_note_public'] = $object->note_public;
        $subs['contact_note_private'] = $object->note_private;

        // Birthday
        if (!empty($object->birthday)) {
            $subs['contact_birthday'] = dol_print_date($object->birthday, 'day');
        } else {
            $subs['contact_birthday'] = '';
        }

        // If contact has a linked thirdparty, also include company tags
        if (!empty($object->socid) && $object->socid > 0) {
            require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
            $thirdparty = new Societe($this->db);
            if ($thirdparty->fetch($object->socid) > 0) {
                // Add company tags for the linked thirdparty
                $company_subs = $this->getThirdpartySubstitutions($thirdparty);
                $subs = array_merge($subs, $company_subs);
            }
        }

        // Extra fields support: {contact_options_xxx}
        if (!empty($object->array_options)) {
            foreach ($object->array_options as $key => $val) {
                $clean_key = preg_replace('/^options_/', '', $key);
                $subs['contact_options_'.$clean_key] = $val;
            }
        }

        return $subs;
    }
}
