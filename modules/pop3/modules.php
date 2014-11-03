<?php

if (!defined('DEBUG_MODE')) { die(); }

require APP_PATH.'modules/pop3/hm-pop3.php';

class Hm_Handler_pop3_message_list_type extends Hm_Handler_Module {
    public function process() {
        if (array_key_exists('list_path', $this->request->get)) {
            $path = $this->request->get['list_path'];
            if (preg_match("/^pop3_\d+$/", $path)) {
                $this->out('list_path', $path);
                $parts = explode('_', $path, 2);
                $details = Hm_POP3_List::dump(intval($parts[1]));
                if (!empty($details)) {
                    if ($details['name'] == 'Default-Auth-Server') {
                        $details['name'] = 'Default';
                    }
                    $this->out('mailbox_list_title', array('POP3', $details['name'], 'INBOX'));
                    $this->out('message_list_since', $this->user_config->get('pop3_since', DEFAULT_SINCE));
                    $this->out('per_source_limit', $this->user_config->get('pop3_limit', DEFAULT_SINCE));
                }
            }
        }
    }
}

class Hm_Handler_process_pop3_limit_setting extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('save_settings', 'pop3_limit'));
        $new_settings = $this->get('new_user_settings', array());
        $settings = $this->get('user_settings', array());

        if ($success) {
            if ($form['pop3_limit'] > MAX_PER_SOURCE || $form['pop3_limit'] < 0) {
                $limit = DEFAULT_PER_SOURCE;
            }
            else {
                $limit = $form['pop3_limit'];
            }
            $new_settings['pop3_limit'] = $limit;
        }
        else {
            $settings['pop3_limit'] = $this->user_config->get('pop3_limit', DEFAULT_PER_SOURCE);
        }
        $this->out('new_user_settings', $new_settings, false);
        $this->out('user_settings', $settings, false);
    }
}

class Hm_Handler_process_pop3_since_setting extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('save_settings', 'pop3_since'));
        $new_settings = $this->get('new_user_settings', array());
        $settings = $this->get('user_settings', array());

        if ($success) {
            $new_settings['pop3_since'] = process_since_argument($form['pop3_since'], true);
        }
        else {
            $settings['pop3_since'] = $this->user_config->get('pop3_since', false);
        }
        $this->out('new_user_settings', $new_settings, false);
        $this->out('user_settings', $settings, false);
    }
}

class Hm_Handler_pop3_status extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('pop3_server_ids'));
        if ($success) {
            $ids = explode(',', $form['pop3_server_ids']);
            foreach ($ids as $id) {
                $start_time = microtime(true);
                $pop3 = Hm_POP3_List::connect($id, false);
                if ($pop3->state == 'authed') {
                    $this->out('pop3_connect_time', microtime(true) - $start_time);
                    $this->out('pop3_connect_status', 'Authenticated');
                    $this->out('pop3_status_server_id', $id);
                }
            }
        }
    }
}

class Hm_Handler_pop3_message_action extends Hm_Handler_Module {
    public function process() {

        list($success, $form) = $this->process_form(array('action_type', 'message_ids'));
        if ($success) {
            $id_list = explode(',', $form['message_ids']);
            foreach ($id_list as $msg_id) {
                if (preg_match("/^pop3_(\d)+_(\d)+$/", $msg_id)) {
                    switch($form['action_type']) {
                        case 'unread':
                            Hm_POP3_Seen_Cache::remove($msg_id);
                            break;
                        case 'read':
                            Hm_POP3_Seen_Cache::add($msg_id);
                            break;
                    }
                }
            }
        }
    }
}

class Hm_Handler_pop3_folder_page extends Hm_Handler_Module {
    public function process() {

        $msgs = array();
        list($success, $form) = $this->process_form(array('pop3_server_id'));
        if ($success) {
            $unread_only = false;
            $login_time = $this->session->get('login_time', false);
            if ($login_time) {
                $this->out('login_time', $login_time);
            }
            $terms = false;
            if (array_key_exists('pop3_search', $this->request->post)) {
                $limit = $this->user_config->get('pop3_limit', DEFAULT_PER_SOURCE);
                $terms = $this->session->get('search_terms', false);
                $since = $this->session->get('search_since', DEFAULT_SINCE);
                $fld = $this->session->get('search_fld', 'TEXT');
                $date = process_since_argument($since);
                $cutoff_timestamp = strtotime($date);
            }
            elseif ($this->get('list_path') == 'unread') {
                $limit = $this->user_config->get('unread_per_source_setting', DEFAULT_PER_SOURCE);
                $date = process_since_argument($this->user_config->get('unread_since_setting', DEFAULT_SINCE));
                $unread_only = true;
                $cutoff_timestamp = strtotime($date);
                if ($login_time && $login_time > $cutoff_timestamp) {
                    $cutoff_timestamp = $login_time;
                }
            }
            elseif ($this->get('list_path') == 'combined_inbox') {
                $limit = $this->user_config->get('all_per_source_setting', DEFAULT_PER_SOURCE);
                $date = process_since_argument($this->user_config->get('all_since_setting', DEFAULT_SINCE));
                $cutoff_timestamp = strtotime($date);
            }
            else {
                $limit = $this->user_config->get('pop3_limit', DEFAULT_PER_SOURCE);
                $date = process_since_argument($this->user_config->get('pop3_since', DEFAULT_SINCE));
                $cutoff_timestamp = strtotime($date);
            }
            $pop3 = Hm_POP3_List::connect($form['pop3_server_id'], false);
            $details = Hm_POP3_List::dump($form['pop3_server_id']);
            $path = sprintf("pop3_%d", $form['pop3_server_id']);
            if ($pop3->state == 'authed') {
                $this->out('pop3_mailbox_page_path', $path);
                $list = array_slice(array_reverse(array_unique(array_keys($pop3->mlist()))), 0, $limit);
                foreach ($list as $id) {
                    $path = sprintf("pop3_%d", $form['pop3_server_id']);
                    $msg_headers = $pop3->msg_headers($id);
                    if (!empty($msg_headers)) {
                        if (isset($msg_headers['date'])) {
                            if (strtotime($msg_headers['date']) < $cutoff_timestamp) {
                                continue;
                            }
                        }
                        if ($unread_only && Hm_POP3_Seen_Cache::is_present(sprintf('pop3_%d_%d', $form['pop3_server_id'], $id))) {
                            continue;
                        }
                        if ($terms) {
                            $body = implode('', $pop3->retr_full($id));
                            if (!search_pop3_msg($body, $msg_headers, $terms, $fld)) {
                                continue;
                            }
                        }
                        $msg_headers['server_name'] = $details['name'];
                        $msg_headers['server_id'] = $form['pop3_server_id'];
                        $msgs[$id] = $msg_headers;
                    }
                }
                $this->out('pop3_mailbox_page', $msgs);
                $this->out('pop3_server_id', $form['pop3_server_id']);
            }
        }
    }
}

class Hm_Handler_pop3_message_content extends Hm_Handler_Module {
    public function process() {

        list($success, $form) = $this->process_form(array('pop3_uid', 'pop3_list_path'));
        if ($success) {
            $id = (int) substr($form['pop3_list_path'], 4);
            $pop3 = Hm_POP3_List::connect($id, false);
            $details = Hm_POP3_List::dump($id);
            if ($pop3->state == 'authed') {
                $msg_lines = $pop3->retr_full($form['pop3_uid']);
                $header_list = array();
                $body = array();
                $headers = true;
                $last_header = false;
                foreach ($msg_lines as $line) {
                    if ($headers) {
                        if (substr($line, 0, 1) == "\t") {
                            $header_list[$last_header] .= ' '.trim($line);
                        }
                        elseif (strstr($line, ':')) {
                            $parts = explode(':', $line, 2);
                            if (count($parts) == 2) {
                                $header_list[$parts[0]] = trim($parts[1]);
                                $last_header = $parts[0];
                            }
                        }
                    }
                    else {
                        $body[] = $line;
                    }
                    if (!trim($line)) {
                        $headers = false;
                    }
                }
                $this->out('pop3_message_headers', $header_list);
                $this->out('pop3_message_body', $body);
                $this->out('pop3_mailbox_page_path', $form['pop3_list_path']);
                $this->out('pop3_server_id', $id);

                Hm_POP3_Seen_Cache::add(sprintf("pop3_%s_%s", $id, $form['pop3_uid']));
            }
        }
    }
}

class Hm_Handler_pop3_save extends Hm_Handler_Module {
    public function process() {
        $just_saved_credentials = false;
        if (isset($this->request->post['pop3_save'])) {
            list($success, $form) = $this->process_form(array('pop3_user', 'pop3_pass', 'pop3_server_id'));
            if (!$success) {
                Hm_Msgs::add('ERRUsername and Password are required to save a connection');
            }
            else {
                $pop3 = Hm_POP3_List::connect($form['pop3_server_id'], false, $form['pop3_user'], $form['pop3_pass'], true);
                if ($pop3->state == 'authed') {
                    $just_saved_credentials = true;
                    Hm_Msgs::add("Server saved");
                    $this->session->record_unsaved('POP3 server saved');
                }
                else {
                    Hm_Msgs::add("ERRUnable to save this server, are the username and password correct?");
                }
            }
        }
        $this->out('just_saved_credentials', $just_saved_credentials);
    }
}

class Hm_Handler_pop3_forget extends Hm_Handler_Module {
    public function process() {
        $just_forgot_credentials = false;
        if (isset($this->request->post['pop3_forget'])) {
            list($success, $form) = $this->process_form(array('pop3_server_id'));
            if ($success) {
                Hm_POP3_List::forget_credentials($form['pop3_server_id']);
                $just_forgot_credentials = true;
                Hm_Msgs::add('Server credentials forgotten');
                $this->session->record_unsaved('POP3 server credentials forgotten');
            }
            else {
                $this->out('old_form', $form);
            }
        }
        $this->out('just_forgot_credentials', $just_forgot_credentials);
    }
}

class Hm_Handler_pop3_delete extends Hm_Handler_Module {
    public function process() {
        if (isset($this->request->post['pop3_delete'])) {
            list($success, $form) = $this->process_form(array('pop3_server_id'));
            if ($success) {
                $res = Hm_POP3_List::del($form['pop3_server_id']);
                if ($res) {
                    $this->out('deleted_server_id', $form['pop3_server_id']);
                    Hm_Msgs::add('Server deleted');
                    $this->session->record_unsaved('POP3 server deleted');
                }
            }
            else {
                $this->out('old_form', $form);
            }
        }
    }
}

class Hm_Handler_pop3_connect extends Hm_Handler_Module {
    public function process() {
        $pop3 = false;
        if (isset($this->request->post['pop3_connect'])) {
            list($success, $form) = $this->process_form(array('pop3_user', 'pop3_pass', 'pop3_server_id'));
            if ($success) {
                $pop3 = Hm_POP3_List::connect($form['pop3_server_id'], false, $form['pop3_user'], $form['pop3_pass']);
            }
            elseif (isset($form['pop3_server_id'])) {
                $pop3 = Hm_POP3_List::connect($form['pop3_server_id'], false);
            }
            if ($pop3 && $pop3->state == 'authed') {
                Hm_Msgs::add("Successfully authenticated to the POP3 server");
            }
            else {
                Hm_Msgs::add("ERRFailed to authenticate to the POP3 server");
            }
        }
    }
}

class Hm_Handler_load_pop3_cache extends Hm_Handler_Module {
    public function process() {
        $servers = Hm_POP3_List::dump();
        $cache = $this->session->get('pop3_cache', array()); 
        foreach ($servers as $index => $server) {
            if (isset($cache[$index])) {
            }
        }
    }
}

class Hm_Handler_save_pop3_cache extends Hm_Handler_Module {
    public function process() {
    }
}

class Hm_Handler_load_pop3_servers_from_config extends Hm_Handler_Module {
    public function process() {
        $servers = $this->user_config->get('pop3_servers', array());
        $added = false;
        foreach ($servers as $index => $server) {
            Hm_POP3_List::add( $server, $index );
            if ($server['name'] == 'Default-Auth-Server') {
                $added = true;
            }
        }
        if (!$added) {
            $auth_server = $this->session->get('pop3_auth_server_settings', array());
            if (!empty($auth_server)) {
                Hm_POP3_List::add(array( 
                    'name' => 'Default-Auth-Server',
                    'server' => $auth_server['server'],
                    'port' => $auth_server['port'],
                    'tls' => $auth_server['tls'],
                    'user' => $auth_server['username'],
                    'pass' => $auth_server['password']),
                count($servers));
                $this->session->del('pop3_auth_server_settings');
            }
        }
        Hm_POP3_Seen_Cache::load($this->session->get('pop3_read_uids', array()));
    }
}

class Hm_Handler_process_add_pop3_server extends Hm_Handler_Module {
    public function process() {
        if (isset($this->request->post['submit_pop3_server'])) {
            list($success, $form) = $this->process_form(array('new_pop3_name', 'new_pop3_address', 'new_pop3_port'));
            if (!$success) {
                $this->out('old_form', $form);
                Hm_Msgs::add('ERRYou must supply a name, a server and a port');
            }
            else {
                $tls = false;
                if (isset($this->request->post['tls'])) {
                    $tls = true;
                }
                if ($con = fsockopen($form['new_pop3_address'], $form['new_pop3_port'], $errno, $errstr, 2)) {
                    Hm_POP3_List::add( array(
                        'name' => $form['new_pop3_name'],
                        'server' => $form['new_pop3_address'],
                        'port' => $form['new_pop3_port'],
                        'tls' => $tls));
                    Hm_Msgs::add('Added server!');
                    $this->session->record_unsaved('POP3 server added');
                }
                else {
                    Hm_Msgs::add(sprintf('ERRCound not add server: %s', $errstr));
                }
            }
        }
    }
}

class Hm_Handler_add_pop3_servers_to_page_data extends Hm_Handler_Module {
    public function process() {
        $servers = Hm_POP3_List::dump();
        $this->out('pop3_servers', $servers);
        if (!empty($servers)) {
            $this->append('email_folders', 'folder_sources');
        }
    }
}

class Hm_Handler_load_pop3_folders extends Hm_Handler_Module {
    public function process() {
        $servers = Hm_POP3_List::dump();
        $folders = array();
        if (!empty($servers)) {
            foreach ($servers as $id => $server) {
                if ($server['name'] == 'Default-Auth-Server') {
                    $server['name'] = 'Default';
                }
                $folders[$id] = $server['name'];
            }
        }
        $this->out('pop3_folders', $folders);
    }
}

class Hm_Handler_save_pop3_servers extends Hm_Handler_Module {
    public function process() {
        $servers = Hm_POP3_List::dump(false, true);
        $this->user_config->set('pop3_servers', $servers);
        $this->session->set('pop3_read_uids', Hm_POP3_Seen_Cache::dump());
        Hm_POP3_List::clean_up();
    }
}

class Hm_Output_add_pop3_server_dialog extends Hm_Output_Module {
    protected function output($input, $format) {
        $count = count($this->get('pop3_servers', array()));
        $count = sprintf($this->trans('%d configured'), $count);
        return '<div class="pop3_server_setup"><div data-target=".pop3_section" class="server_section">'.
            '<img alt="" src="'.Hm_Image_Sources::$env_closed.'" width="16" height="16" />'.
            ' POP3 Servers <div class="server_count">'.$count.'</div></div><div class="pop3_section"><form class="add_server" method="POST">'.
            '<input type="hidden" name="hm_nonce" value="'.$this->html_safe(Hm_Nonce::generate()).'" />'.
            '<div class="subtitle">Add a POP3 Server</div>'.
            '<table><tr><td colspan="2"><input required type="text" name="new_pop3_name" class="txt_fld" value="" placeholder="Account name" /></td></tr>'.
            '<tr><td colspan="2"><input required type="text" name="new_pop3_address" class="txt_fld" placeholder="pop3 server address" value=""/></td></tr>'.
            '<tr><td colspan="2"><input required type="text" name="new_pop3_port" class="port_fld" value="" placeholder="Port"></td></tr>'.
            '<tr><td><input type="checkbox" name="tls" value="1" checked="checked" /> Use TLS</td>'.
            '<td><input type="submit" value="Add" name="submit_pop3_server" /></td></tr>'.
            '</table></form>';
    }
}

class Hm_Output_display_configured_pop3_servers extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        foreach ($this->get('pop3_servers', array()) as $index => $vals) {

            $no_edit = false;

            if (isset($vals['user'])) {
                $disabled = 'disabled="disabled"';
                $user_pc = $vals['user'];
                $pass_pc = '[saved]';
            }
            else {
                $user_pc = '';
                $pass_pc = 'Password';
                $disabled = '';
            }
            if ($vals['name'] == 'Default-Auth-Server') {
                $vals['name'] = 'Default';
                $no_edit = true;
            }
            $res .= '<div class="configured_server">';
            $res .= sprintf('<div class="server_title">%s</div><div class="server_subtitle">%s/%d %s</div>',
                $this->html_safe($vals['name']), $this->html_safe($vals['server']), $this->html_safe($vals['port']), $vals['tls'] ? 'TLS' : '' );
            $res .= 
                '<form class="pop3_connect" method="POST">'.
                '<input type="hidden" name="hm_nonce" value="'.$this->html_safe(Hm_Nonce::generate()).'" />'.
                '<input type="hidden" name="pop3_server_id" value="'.$this->html_safe($index).'" /><span> '.
                '<input '.$disabled.' class="credentials" placeholder="Username" type="text" name="pop3_user" value="'.$user_pc.'"></span>'.
                '<span> <input '.$disabled.' class="credentials pop3_password" placeholder="'.$pass_pc.'" type="password" name="pop3_pass"></span>';
            if (!$no_edit) {
                $res .= '<input type="submit" value="Test" class="test_pop3_connect" />';
                if (!isset($vals['user']) || !$vals['user']) {
                    $res .= '<input type="submit" value="Delete" class="delete_pop3_connection" />';
                    $res .= '<input type="submit" value="Save" class="save_pop3_connection" />';
                }
                else {
                    $res .= '<input type="submit" value="Delete" class="delete_pop3_connection" />';
                    $res .= '<input type="submit" value="Forget" class="forget_pop3_connection" />';
                }
                $res .= '<input type="hidden" value="ajax_pop3_debug" name="hm_ajax_hook" />';
            }
            $res .= '</form></div>';
        }
        $res .= '<br class="clear_float" /></div></div>';
        return $res;
    }
}

class Hm_Output_filter_pop3_folders extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        foreach ($this->get('pop3_folders', array()) as $id => $folder) {
            $res .= '<li class="pop3_'.$this->html_safe($id).'">'.
                '<a href="?page=message_list&list_path=pop3_'.$this->html_safe($id).'">'.
                '<img class="account_icon" alt="Toggle folder" src="'.Hm_Image_Sources::$folder.'" width="16" height="16" /> '.
                $this->html_safe($folder).'</a></li>';
        }
        Hm_Page_Cache::concat('email_folders', $res);
        return '';
    }
}

class Hm_Output_filter_pop3_message_content extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($this->get('pop3_message_headers')) {
            $txt = '';
            $from = '';
            $small_headers = array('subject', 'date', 'from');
            $headers = $this->get('pop3_message_headers');
            $txt .= '<table class="msg_headers">'.
                '<col class="header_name_col"><col class="header_val_col"></colgroup>';
            foreach ($small_headers as $fld) {
                foreach ($headers as $name => $value) {
                    if ($fld == strtolower($name)) {
                        if ($fld == 'from') {
                            $from = $value;
                        }
                        if ($fld == 'subject') {
                            $txt .= '<tr class="header_'.$fld.'"><th colspan="2">';
                            if (isset($headers['Flags']) && stristr($headers['Flags'], 'flagged')) {
                                $txt .= ' <img alt="" class="account_icon" src="'.Hm_Image_Sources::$folder.'" width="16" height="16" /> ';
                            }
                            $txt .= $this->html_safe($value).'</th></tr>';
                        }
                        else {
                            $txt .= '<tr class="header_'.$fld.'"><th>'.$this->html_safe($name).'</th><td>'.$this->html_safe($value).'</td></tr>';
                        }
                        break;
                    }
                }
            }
            foreach ($headers as $name => $value) {
                if (!in_array(strtolower($name), $small_headers)) {
                    $txt .= '<tr style="display: none;" class="long_header"><th>'.$this->html_safe($name).'</th><td>'.$this->html_safe($value).'</td></tr>';
                }
            }
            $txt .= '<tr><th colspan="2" class="header_links">'.
                '<a href="#" class="header_toggle">all</a>'.
                '<a class="header_toggle" style="display: none;" href="#">small</a>'.
                ' | <a href="?page=compose">reply</a>'.
                ' | <a href="?page=compose">forward</a>'.
                ' | <a href="?page=compose">attach</a>'.
                ' | <a data-message-part="0" href="#">raw</a>'.
                ' | <a href="#">flag</a>'.
                '</th></tr></table>';

            $this->out('msg_headers', $txt);
        }
        $txt = '<div class="msg_text_inner">';
        if ($this->get('pop3_message_body')) {
            $txt .= format_msg_text(implode('', $this->get('pop3_message_body')), $this);
        }
        $txt .= '</div>';
        $this->out('msg_text', $txt);
    }
}

class Hm_Output_filter_pop3_message_list extends Hm_Output_Module {
    protected function output($input, $format) {
        $formatted_message_list = array();
        if ($this->get('pop3_mailbox_page')) {
            $style = $this->get('news_list_style') ? 'news' : 'email';
            if ($this->get('is_mobile')) {
                $style = 'news';
            }
            if ($this->get('login_time')) {
                $login_time = $this->get('login_time');
            }
            else {
                $login_time = false;
            }
            $res = format_pop3_message_list($this->get('pop3_mailbox_page'), $this, $style, $login_time, $this->get('list_path'));
            $this->out('formatted_message_list', $res);
        }
    }
}

class Hm_Output_pop3_server_ids extends Hm_Output_Module {
    protected function output($input, $format) {
        return '<input type="hidden" class="pop3_server_ids" value="'.$this->html_safe(implode(',', array_keys($this->get('pop3_servers', array())))).'" />';
    }
}

class Hm_Output_display_pop3_status extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        foreach ($this->get('pop3_servers', array()) as $index => $vals) {
            if ($vals['name'] == 'Default-Auth-Server') {
                $vals['name'] = 'Default';
            }
            $res .= '<tr><td>POP3</td><td>'.$vals['name'].'</td><td class="pop3_status_'.$index.'"></td>'.
                '<td class="pop3_detail_'.$index.'"></td></tr>';
        }
        return $res;
    }
}

class Hm_Output_start_pop3_settings extends Hm_Output_Module {
    protected function output($input, $format) {
        return '<tr><td data-target=".pop3_setting" colspan="2" class="settings_subtitle"><img alt="" src="'.Hm_Image_Sources::$env_closed.'" />POP3 Settings</td></tr>';
    }
}

class Hm_Output_pop3_since_setting extends Hm_Output_Module {
    protected function output($input, $format) {
        $since = false;
        $settings = $this->get('user_settings', array());
        if (array_key_exists('pop3_since', $settings)) {
            $since = $settings['pop3_since'];
        }
        return '<tr class="pop3_setting"><td>Show messages received since</td><td>'.message_since_dropdown($since, 'pop3_since', $this).'</td></tr>';
    }
}

class Hm_Output_pop3_limit_setting extends Hm_Output_Module {
    protected function output($input, $format) {
        $limit = DEFAULT_PER_SOURCE;
        $settings = $this->get('user_settings', array());
        if (array_key_exists('pop3_limit', $settings)) {
            $limit = $settings['pop3_limit'];
        }
        return '<tr class="pop3_setting"><td>Max messages to display</td><td><input type="text" name="pop3_limit" size="2" value="'.$this->html_safe($limit).'" /></td></tr>';
    }
}

class Hm_Output_filter_pop3_status_data extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($this->get('pop3_connect_status') == 'Authenticated') {
            $this->out('pop3_status_display', '<span class="online">'.
                $this->html_safe(ucwords($this->get('pop3_connect_status'))).'</span> in '.round($this->get('pop3_connect_time'), 3));
        }
        else {
            $this->out('pop3_status_display', '<span class="down">Down</span>');
        }
    }
}


function format_pop3_message_list($msg_list, $output_module, $style, $login_time, $list_parent) {
    $res = array();
    foreach($msg_list as $msg_id => $msg) {
        if ($msg['server_name'] == 'Default-Auth-Server') {
            $msg['server_name'] = 'Default';
        }
        $id = sprintf("pop3_%s_%s", $msg['server_id'], $msg_id);
        $subject = display_value('subject', $msg);;
        $from = display_value('from', $msg);
        if ($style == 'email' && !$from) {
            $from = '[No From]';
        }
        $date = display_value('date', $msg);
        $timestamp = display_value('date', $msg, 'time');
        $url = '?page=message&uid='.$msg_id.'&list_path='.sprintf('pop3_%d', $msg['server_id']).'&list_parent='.$list_parent;
        if (Hm_POP3_Seen_Cache::is_present($id)) {
            $flags = array();
        }
        elseif (isset($msg['date']) && $login_time && strtotime($msg['date']) <= $login_time) {
            $flags = array();
        }
        else {
            $flags = array('unseen');
        }
        $res[$id] = message_list_row($subject, $date, $timestamp, $from, $msg['server_name'], $id, $flags, $style, $url, $output_module);
    }
    return $res;
}

function search_pop3_msg($body, $headers, $terms, $fld) {
    if ($fld == 'TEXT') {
        if (stristr($body, $terms)) {
            return true;
        }
    }
    if ($fld == 'SUBJECT') {
        if (array_key_exists('subject', $headers) && stristr($headers['subject'], $terms)) {
            return true;
        }
    }
    if ($fld == 'FROM') {
        if (array_key_exists('from', $headers) && stristr($headers['from'], $terms)) {
            return true;
        }
    }
}

?>
