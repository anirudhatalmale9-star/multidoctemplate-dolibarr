<?php
/* Copyright (C) 2024
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       archives.php
 * \brief      Archive generation and management for thirdparty/contact
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/contact.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once __DIR__.'/class/template.class.php';
require_once __DIR__.'/class/archive.class.php';
require_once __DIR__.'/class/documentgenerator.class.php';

// Load translations
$langs->loadLangs(array('companies', 'multidoctemplate@multidoctemplate'));

// Get parameters
$id = GETPOST('id', 'int');
$object_type = GETPOST('object_type', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$template_id = GETPOST('template_id', 'int');
$archive_id = GETPOST('archive_id', 'int');
$category_id = GETPOST('category_id', 'int');

// Default object type
if (empty($object_type)) {
    $object_type = 'thirdparty';
}

// Security check
if (!$user->hasRight('societe', 'lire')) {
    accessforbidden();
}

// Initialize objects
$template = new MultiDocTemplate($db);
$archive = new MultiDocArchive($db);
$generator = new MultiDocGenerator($db);

// Load main object
if ($object_type == 'thirdparty') {
    $object = new Societe($db);
} else {
    $object = new Contact($db);
}

if ($id > 0) {
    $result = $object->fetch($id);
    if ($result < 0) {
        dol_print_error($db);
        exit;
    }
}

if (empty($object->id)) {
    accessforbidden('ErrorRecordNotFound');
}

// Get object's categories/tags for filtering
$object_categories = array();
if ($object_type == 'thirdparty') {
    $c = new Categorie($db);
    $cats = $c->containing($object->id, Categorie::TYPE_CUSTOMER);
    if (is_array($cats)) {
        foreach ($cats as $cat) {
            $object_categories[$cat->id] = $cat->label;
        }
    }
    $cats = $c->containing($object->id, Categorie::TYPE_SUPPLIER);
    if (is_array($cats)) {
        foreach ($cats as $cat) {
            $object_categories[$cat->id] = $cat->label;
        }
    }
}

/*
 * Actions
 */

// Generate archive from template
if ($action == 'generate' && $template_id > 0 && $user->hasRight('multidoctemplate', 'archive_creer')) {
    $template->fetch($template_id);
    $convert_to_pdf = GETPOST('convert_to_pdf', 'int');

    if ($template->id > 0) {
        // Determine tag filter from object's categories
        $tag_filter = '';
        if ($category_id > 0 && isset($object_categories[$category_id])) {
            $tag_filter = $object_categories[$category_id];
        }

        // Generate document (with optional PDF conversion for ODT files)
        $result = $generator->generate($template, $object, $object_type, $user, $tag_filter, $convert_to_pdf);

        if ($result > 0) {
            $msg = $langs->trans('ArchiveGeneratedSuccess');
            if ($convert_to_pdf && strtolower($template->filetype) == 'odt') {
                $msg .= ' (PDF)';
            }
            setEventMessages($msg, null, 'mesgs');
        } else {
            setEventMessages($generator->error, $generator->errors, 'errors');
        }
    } else {
        setEventMessages($langs->trans('ErrorTemplateNotFound'), null, 'errors');
    }

    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type);
    exit;
}

// Direct file upload
if ($action == 'directupload' && $user->hasRight('multidoctemplate', 'archive_creer')) {
    if (!empty($_FILES['directfile']['name'])) {
        $filename = $_FILES['directfile']['name'];
        $upload_tag = GETPOST('upload_tag', 'alphanohtml');
        $upload_label = GETPOST('upload_label', 'alphanohtml');

        if (empty($upload_tag)) {
            setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Tag')), null, 'errors');
        } else {
            // Get upload directory
            $upload_dir = MultiDocArchive::getArchiveDir($object_type, $object->id, $upload_tag);

            // Create directory if not exists
            if (!is_dir($upload_dir)) {
                dol_mkdir($upload_dir);
            }

            // Sanitize filename
            $sanitized_filename = dol_sanitizeFileName($filename);
            // Add timestamp to avoid conflicts
            $basename = pathinfo($sanitized_filename, PATHINFO_FILENAME);
            $extension = pathinfo($sanitized_filename, PATHINFO_EXTENSION);
            $final_filename = $basename.'_'.date('YmdHis').'.'.$extension;
            $filepath = $upload_dir.'/'.$final_filename;

            // Move uploaded file
            if (dol_move_uploaded_file($_FILES['directfile']['tmp_name'], $filepath, 1, 0, $_FILES['directfile']['error']) > 0) {
                // Create archive record
                $archive->ref = MultiDocArchive::generateRef($object_type, $object->id);
                $archive->fk_template = 0;  // No template - direct upload
                $archive->object_type = $object_type;
                $archive->object_id = $object->id;
                $archive->filename = $final_filename;
                $archive->filepath = $filepath;
                $archive->filetype = strtolower($extension);
                $archive->filesize = filesize($filepath);
                $archive->tag_filter = $upload_tag;

                $result = $archive->create($user);

                if ($result > 0) {
                    setEventMessages($langs->trans('FileUploadedSuccessfully'), null, 'mesgs');
                } else {
                    // Delete file if DB insert failed
                    dol_delete_file($filepath);
                    setEventMessages($archive->error, null, 'errors');
                }
            } else {
                setEventMessages($langs->trans('ErrorFileUploadFailed'), null, 'errors');
            }
        }
    } else {
        setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('File')), null, 'errors');
    }

    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type);
    exit;
}

// Delete archive
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->hasRight('multidoctemplate', 'archive_supprimer')) {
    if ($archive_id > 0) {
        $archive->fetch($archive_id);
        if ($archive->id > 0) {
            $result = $archive->delete($user);
            if ($result > 0) {
                setEventMessages($langs->trans('ArchiveDeleted'), null, 'mesgs');
            } else {
                setEventMessages($archive->error, null, 'errors');
            }
        }
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type);
    exit;
}

/*
 * View
 */

$title = $langs->trans('Archives').' - '.$object->name;
llxHeader('', $title);

// Custom tooltip styles
print '<style>
.mdt-tooltip-container {
    position: relative;
    display: inline-block;
}
.mdt-tooltip {
    position: fixed;
    background-color: #333;
    color: #fff;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    max-width: 300px;
    z-index: 99999;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    pointer-events: none;
    white-space: normal;
    word-wrap: break-word;
}
.mdt-tooltip::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 20px;
    border-width: 6px;
    border-style: solid;
    border-color: #333 transparent transparent transparent;
}
</style>';

// Prepare tabs
if ($object_type == 'thirdparty') {
    $head = societe_prepare_head($object);
    print dol_get_fiche_head($head, 'archives', $langs->trans('ThirdParty'), -1, 'company');
    $linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
    dol_banner_tab($object, 'socid', $linkback, ($user->hasRight('societe', 'lire') ? 1 : 0), 'rowid', 'nom');
} else {
    $head = contact_prepare_head($object);
    print dol_get_fiche_head($head, 'archives', $langs->trans('Contact'), -1, 'contact');
    $linkback = '<a href="'.DOL_URL_ROOT.'/contact/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
    dol_banner_tab($object, 'id', $linkback, ($user->hasRight('societe', 'lire') ? 1 : 0), 'rowid', 'name');
}

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';
print '</div>';

print dol_get_fiche_end();

// Delete confirmation dialog
if ($action == 'delete') {
    $form = new Form($db);
    $formconfirm = $form->formconfirm(
        $_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type.'&archive_id='.$archive_id,
        $langs->trans('DeleteArchive'),
        $langs->trans('ConfirmDeleteArchive'),
        'confirm_delete',
        '',
        0,
        1
    );
    print $formconfirm;
}

// Generate archive section - File Explorer Style
if ($user->hasRight('multidoctemplate', 'archive_creer')) {
    print '<div class="tabsAction">';
    print load_fiche_titre($langs->trans('GenerateArchive'), '', '');

    // Get available templates for user
    $templates = $template->fetchAllForUser($user);

    if (is_array($templates) && count($templates) > 0) {
        // Group templates by tag
        $templates_by_tag = array();
        foreach ($templates as $tpl) {
            $tag_label = !empty($tpl->tag) ? $tpl->tag : $langs->trans('NoTag');
            if (!isset($templates_by_tag[$tag_label])) {
                $templates_by_tag[$tag_label] = array();
            }
            $templates_by_tag[$tag_label][] = $tpl;
        }
        ksort($templates_by_tag);

        // Search box
        print '<div class="marginbottomonly">';
        print '<input type="text" id="template_search" class="flat minwidth200" placeholder="'.$langs->trans('Search').'..." onkeyup="filterTemplates()">';
        print ' <a href="javascript:void(0)" onclick="expandAllFolders()" class="mdt-tooltip-trigger" data-tooltip="'.$langs->trans('ExpandAll').'">'.img_picto('', 'folder-open').'</a>';
        print ' <a href="javascript:void(0)" onclick="collapseAllFolders()" class="mdt-tooltip-trigger" data-tooltip="'.$langs->trans('CollapseAll').'">'.img_picto('', 'folder').'</a>';
        print '</div>';

        print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type.'" method="POST" id="generate_form">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="generate">';
        print '<input type="hidden" name="template_id" id="selected_template_id" value="">';

        // File explorer style container
        print '<div id="template_explorer" class="div-table-responsive" style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fafafa;">';

        foreach ($templates_by_tag as $tag_label => $tag_templates) {
            $tag_id = 'tag_'.md5($tag_label);
            $count = count($tag_templates);

            // Folder header (collapsible)
            print '<div class="template-folder" data-tag="'.dol_escape_htmltag(strtolower($tag_label)).'">';
            print '<div class="folder-header" onclick="toggleFolder(\''.$tag_id.'\')" style="cursor: pointer; padding: 8px; background: #e8e8e8; margin-bottom: 2px; border-radius: 3px;">';
            print '<span id="'.$tag_id.'_icon">'.img_picto('', 'folder-open').'</span> ';
            print '<strong>'.dol_escape_htmltag($tag_label).'</strong>';
            print ' <span class="opacitymedium">('.$count.')</span>';
            print '</div>';

            // Folder content (templates list)
            print '<div id="'.$tag_id.'_content" class="folder-content" style="margin-left: 25px; display: block;">';
            foreach ($tag_templates as $tpl) {
                print '<div class="template-item" data-label="'.dol_escape_htmltag(strtolower($tpl->label)).'" style="padding: 5px; border-bottom: 1px solid #eee;">';
                print '<a href="javascript:void(0)" onclick="selectTemplate('.$tpl->id.', \''.dol_escape_js($tpl->label).'\', \''.strtolower($tpl->filetype).'\')" style="text-decoration: none;">';
                print img_picto('', 'file', 'style="vertical-align: middle;"').' ';
                print '<span class="template-label">'.dol_escape_htmltag($tpl->label).'</span>';
                print ' <span class="opacitymedium">('.strtoupper($tpl->filetype).')</span>';
                print '</a>';
                // Info icon with description tooltip (custom tooltip to avoid clipping)
                if (!empty($tpl->description)) {
                    print ' <span class="mdt-tooltip-trigger" data-tooltip="'.dol_escape_htmltag($tpl->description).'">'.img_picto('', 'info', 'style="vertical-align: middle; cursor: help;"').'</span>';
                }
                print '</div>';
            }
            print '</div>'; // folder-content
            print '</div>'; // template-folder
        }

        print '</div>'; // template_explorer

        // Selected template display and generate button
        print '<div class="margintoponlyonly" style="margin-top: 15px;">';
        print '<strong>'.$langs->trans('Selected').':</strong> <span id="selected_template_name" class="opacitymedium">'.$langs->trans('None').'</span>';
        print ' &nbsp; ';
        print '<input type="submit" class="button button-primary" value="'.$langs->trans('Generate').'" id="generate_btn" disabled>';
        // PDF conversion checkbox (for ODT files)
        print ' &nbsp; <label id="convert_pdf_label" style="display:none;"><input type="checkbox" name="convert_to_pdf" id="convert_to_pdf" value="1"> '.$langs->trans('ConvertToPdf').'</label>';
        print '</div>';

        print '</form>';

        // JavaScript for file explorer functionality
        $folder_open_icon = img_picto('', 'folder-open');
        $folder_closed_icon = img_picto('', 'folder');
        print '<script type="text/javascript">
var folderOpenIcon = \''.dol_escape_js($folder_open_icon).'\';
var folderClosedIcon = \''.dol_escape_js($folder_closed_icon).'\';

function toggleFolder(tagId) {
    var content = document.getElementById(tagId + "_content");
    var icon = document.getElementById(tagId + "_icon");
    if (content.style.display === "none") {
        content.style.display = "block";
        icon.innerHTML = folderOpenIcon;
    } else {
        content.style.display = "none";
        icon.innerHTML = folderClosedIcon;
    }
}

function expandAllFolders() {
    var contents = document.querySelectorAll(".folder-content");
    var icons = document.querySelectorAll("[id$=\'_icon\']");
    contents.forEach(function(el) { el.style.display = "block"; });
    icons.forEach(function(el) { el.innerHTML = folderOpenIcon; });
}

function collapseAllFolders() {
    var contents = document.querySelectorAll(".folder-content");
    var icons = document.querySelectorAll("[id$=\'_icon\']");
    contents.forEach(function(el) { el.style.display = "none"; });
    icons.forEach(function(el) { el.innerHTML = folderClosedIcon; });
}

function selectTemplate(id, label, filetype) {
    document.getElementById("selected_template_id").value = id;
    document.getElementById("selected_template_name").innerHTML = label;
    document.getElementById("selected_template_name").className = "";
    document.getElementById("generate_btn").disabled = false;
    // Show/hide PDF conversion checkbox (only for ODT files)
    var pdfLabel = document.getElementById("convert_pdf_label");
    if (pdfLabel) {
        if (filetype === "odt") {
            pdfLabel.style.display = "inline";
        } else {
            pdfLabel.style.display = "none";
            document.getElementById("convert_to_pdf").checked = false;
        }
    }
    // Highlight selected
    var items = document.querySelectorAll(".template-item");
    items.forEach(function(el) { el.style.background = ""; });
    event.target.closest(".template-item").style.background = "#d4edda";
}

function filterTemplates() {
    var search = document.getElementById("template_search").value.toLowerCase();
    var folders = document.querySelectorAll(".template-folder");

    folders.forEach(function(folder) {
        var items = folder.querySelectorAll(".template-item");
        var hasVisible = false;

        items.forEach(function(item) {
            var label = item.getAttribute("data-label");
            if (label.indexOf(search) > -1 || search === "") {
                item.style.display = "block";
                hasVisible = true;
            } else {
                item.style.display = "none";
            }
        });

        // Show/hide folder based on whether it has visible items
        var tag = folder.getAttribute("data-tag");
        if (hasVisible || tag.indexOf(search) > -1 || search === "") {
            folder.style.display = "block";
            // Expand folder when searching
            if (search !== "") {
                var content = folder.querySelector(".folder-content");
                var icon = folder.querySelector("[id$=\'_icon\']");
                if (content) content.style.display = "block";
                if (icon) icon.innerHTML = folderOpenIcon;
            }
        } else {
            folder.style.display = "none";
        }
    });
}

// Custom tooltip functionality
(function() {
    var tooltip = null;

    function showTooltip(e) {
        var trigger = e.target.closest(".mdt-tooltip-trigger");
        if (!trigger) return;

        var text = trigger.getAttribute("data-tooltip");
        if (!text) return;

        // Create tooltip element
        tooltip = document.createElement("div");
        tooltip.className = "mdt-tooltip";
        tooltip.textContent = text;
        document.body.appendChild(tooltip);

        // Position tooltip above the trigger
        var rect = trigger.getBoundingClientRect();
        var tooltipRect = tooltip.getBoundingClientRect();
        var left = rect.left;
        var top = rect.top - tooltipRect.height - 10;

        // Adjust if tooltip goes off-screen
        if (left + tooltipRect.width > window.innerWidth) {
            left = window.innerWidth - tooltipRect.width - 10;
        }
        if (top < 0) {
            top = rect.bottom + 10;
            tooltip.style.setProperty("--arrow-position", "top");
        }

        tooltip.style.left = left + "px";
        tooltip.style.top = top + "px";
    }

    function hideTooltip() {
        if (tooltip && tooltip.parentNode) {
            tooltip.parentNode.removeChild(tooltip);
            tooltip = null;
        }
    }

    document.addEventListener("mouseover", showTooltip);
    document.addEventListener("mouseout", function(e) {
        if (e.target.closest(".mdt-tooltip-trigger")) {
            hideTooltip();
        }
    });
})();
</script>';

    } else {
        print '<div class="opacitymedium">'.$langs->trans('NoTemplatesAvailable').'</div>';
    }

    print '</div>';
}

// Direct Upload Form
if ($user->hasRight('multidoctemplate', 'archive_creer')) {
    print '<div class="tabsAction">';
    print load_fiche_titre($langs->trans('UploadFileDirectly'), '', 'upload');

    print '<form enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type.'" method="POST">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="directupload">';

    print '<div class="fichecenter">';
    print '<table class="noborder centpercent">';

    // Tag (folder)
    print '<tr class="oddeven">';
    print '<td class="titlefield">'.$langs->trans('Tag').' <span class="star">*</span></td>';
    print '<td><input type="text" name="upload_tag" size="40" class="flat" placeholder="e.g. CONTRATOS, FACTURAS" required></td>';
    print '</tr>';

    // Label (optional)
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('Label').' ('.$langs->trans('Optional').')</td>';
    print '<td><input type="text" name="upload_label" size="40" class="flat"></td>';
    print '</tr>';

    // File
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('File').' <span class="star">*</span></td>';
    print '<td><input type="file" name="directfile" class="flat" required></td>';
    print '</tr>';

    print '</table>';
    print '</div>';

    print '<div class="center">';
    print '<input type="submit" class="button button-primary" value="'.$langs->trans('Upload').'">';
    print '</div>';

    print '</form>';
    print '</div>';
}

// List of archives - File Explorer Style with collapsible folders
print '<br>';
print load_fiche_titre($langs->trans('ArchivesList'), '', '');

$archives = $archive->fetchAllByObject($object_type, $object->id);

if (is_array($archives) && count($archives) > 0) {
    // Group archives by tag
    $archives_by_tag = array();
    foreach ($archives as $arch) {
        $tag_label = !empty($arch->template_tag) ? $arch->template_tag : $langs->trans('NoTag');
        if (!isset($archives_by_tag[$tag_label])) {
            $archives_by_tag[$tag_label] = array();
        }
        $archives_by_tag[$tag_label][] = $arch;
    }
    ksort($archives_by_tag);

    // Search and controls
    print '<div class="marginbottomonly">';
    print '<input type="text" id="archive_search" class="flat minwidth200" placeholder="'.$langs->trans('Search').'..." onkeyup="filterArchives()">';
    print ' <a href="javascript:void(0)" onclick="expandAllArchiveFolders()" class="mdt-tooltip-trigger" data-tooltip="'.$langs->trans('ExpandAll').'">'.img_picto('', 'folder-open').'</a>';
    print ' <a href="javascript:void(0)" onclick="collapseAllArchiveFolders()" class="mdt-tooltip-trigger" data-tooltip="'.$langs->trans('CollapseAll').'">'.img_picto('', 'folder').'</a>';
    print '</div>';

    // File explorer style container
    print '<div id="archive_explorer" class="div-table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fafafa;">';

    foreach ($archives_by_tag as $tag_label => $tag_archives) {
        $tag_id = 'arch_tag_'.md5($tag_label);
        $count = count($tag_archives);

        // Folder header (collapsible)
        print '<div class="archive-folder" data-tag="'.dol_escape_htmltag(strtolower($tag_label)).'">';
        print '<div class="folder-header" onclick="toggleArchiveFolder(\''.$tag_id.'\')" style="cursor: pointer; padding: 8px; background: #e8e8e8; margin-bottom: 2px; border-radius: 3px;">';
        print '<span id="'.$tag_id.'_icon">'.img_picto('', 'folder-open').'</span> ';
        print '<strong>'.dol_escape_htmltag($tag_label).'</strong>';
        print ' <span class="opacitymedium">('.$count.' '.$langs->trans('Files').')</span>';
        print '</div>';

        // Folder content (archives list)
        print '<div id="'.$tag_id.'_content" class="archive-folder-content" style="margin-left: 10px; display: block;">';

        // Table header for this folder
        print '<table class="noborder centpercent" style="margin-bottom: 10px;">';
        print '<tr class="liste_titre">';
        print '<th class="sortable" onclick="sortArchiveTable(\''.$tag_id.'\', 0)" style="cursor: pointer;">'.$langs->trans('Label').' ↕</th>';
        print '<th class="sortable" onclick="sortArchiveTable(\''.$tag_id.'\', 1)" style="cursor: pointer;">'.$langs->trans('Filename').' ↕</th>';
        print '<th class="center sortable" onclick="sortArchiveTable(\''.$tag_id.'\', 2)" style="cursor: pointer;">'.$langs->trans('DateGeneration').' ↕</th>';
        print '<th class="center">'.$langs->trans('Actions').'</th>';
        print '</tr>';

        foreach ($tag_archives as $arch) {
            $is_direct_upload = empty($arch->fk_template);
            $label_display = !empty($arch->template_label) ? $arch->template_label : ($is_direct_upload ? $langs->trans('DirectUpload') : '-');
            print '<tr class="oddeven archive-row" data-label="'.dol_escape_htmltag(strtolower($label_display)).'" data-filename="'.dol_escape_htmltag(strtolower($arch->filename)).'" data-date="'.$arch->date_generation.'">';

            // Label (template label or Direct Upload badge)
            print '<td>';
            if ($is_direct_upload) {
                print '<span class="badge badge-info">'.$langs->trans('DirectUpload').'</span>';
            } else {
                print '<strong>'.dol_escape_htmltag($label_display).'</strong>';
            }
            print '</td>';

            // Filename (with download link)
            print '<td>';
            if (file_exists($arch->filepath)) {
                $relative_path = str_replace(DOL_DATA_ROOT.'/multidoctemplate/', '', $arch->filepath);
                print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=multidoctemplate&file='.urlencode($relative_path).'" target="_blank">';
                print img_picto('', 'file', 'style="vertical-align: middle;"').' '.dol_escape_htmltag($arch->filename);
                print '</a>';
            } else {
                print '<span class="opacitymedium">'.img_picto('', 'file').' '.dol_escape_htmltag($arch->filename).' ('.$langs->trans('FileNotFound').')</span>';
            }
            print '</td>';

            // Date generation
            print '<td class="center">'.dol_print_date($arch->date_generation, 'dayhour').'</td>';

            // Actions
            print '<td class="center nowraponall">';
            if (file_exists($arch->filepath)) {
                $relative_path = str_replace(DOL_DATA_ROOT.'/multidoctemplate/', '', $arch->filepath);
                print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=multidoctemplate&file='.urlencode($relative_path).'" target="_blank">';
                print img_picto($langs->trans('Download'), 'download');
                print '</a> ';
            }
            if ($user->hasRight('multidoctemplate', 'archive_supprimer')) {
                print '<a class="deletefilelink" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&object_type='.$object_type.'&action=delete&archive_id='.$arch->id.'&token='.newToken().'">';
                print img_picto($langs->trans('Delete'), 'delete');
                print '</a>';
            }
            print '</td>';

            print '</tr>';
        }

        print '</table>';
        print '</div>'; // archive-folder-content
        print '</div>'; // archive-folder
    }

    print '</div>'; // archive_explorer

    // JavaScript for archive explorer functionality
    print '<script type="text/javascript">
function toggleArchiveFolder(tagId) {
    var content = document.getElementById(tagId + "_content");
    var icon = document.getElementById(tagId + "_icon");
    if (content.style.display === "none") {
        content.style.display = "block";
        icon.innerHTML = folderOpenIcon;
    } else {
        content.style.display = "none";
        icon.innerHTML = folderClosedIcon;
    }
}

function expandAllArchiveFolders() {
    var contents = document.querySelectorAll(".archive-folder-content");
    var icons = document.querySelectorAll("[id^=\'arch_tag_\'][id$=\'_icon\']");
    contents.forEach(function(el) { el.style.display = "block"; });
    icons.forEach(function(el) { el.innerHTML = folderOpenIcon; });
}

function collapseAllArchiveFolders() {
    var contents = document.querySelectorAll(".archive-folder-content");
    var icons = document.querySelectorAll("[id^=\'arch_tag_\'][id$=\'_icon\']");
    contents.forEach(function(el) { el.style.display = "none"; });
    icons.forEach(function(el) { el.innerHTML = folderClosedIcon; });
}

function filterArchives() {
    var search = document.getElementById("archive_search").value.toLowerCase();
    var folders = document.querySelectorAll(".archive-folder");

    folders.forEach(function(folder) {
        var rows = folder.querySelectorAll(".archive-row");
        var hasVisible = false;

        rows.forEach(function(row) {
            var filename = row.getAttribute("data-filename");
            if (filename.indexOf(search) > -1 || search === "") {
                row.style.display = "";
                hasVisible = true;
            } else {
                row.style.display = "none";
            }
        });

        var tag = folder.getAttribute("data-tag");
        if (hasVisible || tag.indexOf(search) > -1 || search === "") {
            folder.style.display = "block";
            if (search !== "") {
                var content = folder.querySelector(".archive-folder-content");
                var icon = folder.querySelector("[id$=\'_icon\']");
                if (content) content.style.display = "block";
                if (icon) icon.innerHTML = folderOpenIcon;
            }
        } else {
            folder.style.display = "none";
        }
    });
}

var sortDirections = {};
function sortArchiveTable(tagId, colIndex) {
    var content = document.getElementById(tagId + "_content");
    var table = content.querySelector("table");
    var tbody = table.querySelector("tbody") || table;
    var rows = Array.from(tbody.querySelectorAll("tr.archive-row"));

    var key = tagId + "_" + colIndex;
    sortDirections[key] = !sortDirections[key];
    var ascending = sortDirections[key];

    rows.sort(function(a, b) {
        var aVal, bVal;
        if (colIndex === 0) {
            aVal = a.getAttribute("data-label");
            bVal = b.getAttribute("data-label");
        } else if (colIndex === 1) {
            aVal = a.getAttribute("data-filename");
            bVal = b.getAttribute("data-filename");
        } else {
            aVal = a.getAttribute("data-date");
            bVal = b.getAttribute("data-date");
        }

        if (aVal < bVal) return ascending ? -1 : 1;
        if (aVal > bVal) return ascending ? 1 : -1;
        return 0;
    });

    rows.forEach(function(row) {
        tbody.appendChild(row);
    });
}
</script>';

} else {
    print '<div class="opacitymedium">'.$langs->trans('NoArchivesYet').'</div>';
}

llxFooter();
$db->close();
