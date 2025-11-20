<?php

namespace FluentBoardsPro\App\Hooks\Handlers;


use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Models\User;
use FluentBoardsPro\App\Core\App as ProApp;

class InvitationHandler
{
    /**
    * Send invitation email to the specified email address
    * This function generates a unique hash code for the invitation, saves it in the database,
    * and sends an email to the invitee with a link to join the board.
    */
    public function sendInvitationViaEmail($boardId, $email, $current_user_id)
    {
        try {
            $userData = $this->getUserData($current_user_id);

            $board = Board::findOrFail($boardId) ?? null;
            if (!$board) {
                return;
            }

            $hashCode = $this->generateHash(32); // Generate a unique hash code of 32 characters

            // Build invite URL without exposing email
            $siteUrl = add_query_arg( array(
                'fbs'        => 1,
                'invitation' => 'board',
                'hash'       => $hashCode,
                'bid'        => $boardId
            ), site_url('index.php') );

            $data = [
                'body'        => __('has invited you to join board: ','fluent-boards'). $board->title ,
                'pre_header'  => __('join board invitation in fluent boards', 'fluent-boards'),
                'btn_title'   => __('Join Board', 'fluent-boards'),
                'show_footer' => true,
                'userData'    => $userData,
                'boardLink'   => $siteUrl,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];

            $mailSubject = __('Invitation for joining board','fluent-boards');
            $message = Helper::loadView('emails.invite-external', $data);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            $this->saveHashByEmail($boardId, $email, $hashCode);

            \wp_mail($email, $mailSubject, $message, $headers);
        } catch (\Exception $e) {
            // do nothing // better to log here
            error_log($e->getMessage(), 0);
        }
    }


    /*
     * Render board member invitation form for new user
     */
    public function showInviteeRegistrationForm($boardId, $hashCode)
    {
        $activeHashCodes = $this->getActiveHashCodes($boardId);

        $inviteMeta = null;
        foreach ($activeHashCodes as $hashCodes) {
            $value = maybe_unserialize($hashCodes->value);
            if (isset($value['hash']) && $value['hash'] === $hashCode) {
                $inviteMeta = [
                    'record'     => $hashCodes,
                    'email'      => isset($value['email']) ? $value['email'] : '',
                    'issued_at'  => isset($value['issued_at']) ? (int) $value['issued_at'] : 0,
                    'expires_at' => isset($value['expires_at']) ? (int) $value['expires_at'] : 0,
                    'used'       => isset($value['used']) ? (bool) $value['used'] : false,
                ];
                break;
            }
        }

        if (!$inviteMeta || empty($inviteMeta['email'])) {
            status_header(404);
            die('Invitation not found');
        }

        if (!empty($inviteMeta['used'])) {
            status_header(410);
            die('This invitation link has already been used');
        }

        if (!empty($inviteMeta['expires_at']) && time() > $inviteMeta['expires_at']) {
            status_header(410);
            die('This invitation link has expired');
        }

        $app = ProApp::getInstance();

        // generate short-lived hmac signature for form submit binding
        $ts  = time();
        $sig = hash_hmac('sha256', $boardId . '|' . $inviteMeta['email'] . '|' . $hashCode . '|' . $ts, wp_salt('fbs_invite'));

        $app->view->render('register_member_form', [
            'boardId' => $boardId,
            'email'   => $inviteMeta['email'],
            'hash'    => $hashCode,
            'ts'      => $ts,
            'sig'     => $sig
        ]);
        
        exit();
        
    }

    /*
    * Process the invitation form submission
    * This function handles the form submission after accepting an invitation to join a board.
    * It validates the invitation, creates a new user, adds them to the board, and logs them in.
    * It also ensures the invitation is valid, not expired, and not already used.
    */
    public function processInvitation()
    {
        $password = sanitize_text_field($_REQUEST['password']);
        $firstname = sanitize_text_field($_REQUEST['firstname']);
        $lastname = sanitize_text_field($_REQUEST['lastname']);
        $postedEmail = isset($_REQUEST['email']) ? sanitize_email($_REQUEST['email']) : '';
        $boardId = sanitize_text_field($_REQUEST['board_id']);
        $hashCode = sanitize_text_field($_REQUEST['hash']);
        $ts      = isset($_REQUEST['ts']) ? (int) $_REQUEST['ts'] : 0;
        $sig     = isset($_REQUEST['sig']) ? sanitize_text_field($_REQUEST['sig']) : '';

        // Find the invite and derive the email from stored meta
        $invite = $this->findInviteMeta($boardId, $hashCode);
        if (!$invite) {
            echo 'Error: Invalid user';
            exit;
        }

        // Enforce expiry and used flags
        $now = time();
        if (!empty($invite['expires_at']) && $now > (int) $invite['expires_at']) {
            status_header(410);
            die('Invitation expired');
        }
        if (!empty($invite['used'])) {
            status_header(410);
            die('Invitation already used');
        }

        // Verify HMAC signature (15-minute window)
        if (empty($ts) || empty($sig) || abs($now - $ts) > 15 * MINUTE_IN_SECONDS) {
            status_header(400);
            die('Invalid or expired form token');
        }
        $expectedSig = hash_hmac('sha256', $boardId . '|' . $invite['email'] . '|' . $hashCode . '|' . $ts, wp_salt('fbs_invite'));
        if (!hash_equals($expectedSig, $sig)) {
            status_header(400);
            die('Invalid form signature');
        }

        // Use email from invite, ignore posted value
        $email = $invite['email'];

        // Validate email
        if (!is_email($email) || $email !== $postedEmail) {
            echo 'Error: Invalid email address';
            die();
        }

        $user_data = array(
            'user_login'    =>  $email,    // User login (username)
            'user_pass'     =>  $password,    // User password
            'user_email'    =>  $email,       // User email address
            'first_name'    =>  $firstname,       // User firstname
            'last_name'     =>  $lastname,       // User lastname
            'role'          => 'subscriber', // User role (optional)
        );

        // Insert the user into the database
        $userId = wp_insert_user($user_data);

        // Check if the user was successfully created
        if (is_wp_error($userId)) {
            // There was an error creating the user
            echo 'Error: ' . $userId->get_error_message();
        } else {
            $this->makeBoardMember($boardId, $userId);
            $login_data = array();
            $login_data['user_login'] = $email;
            $login_data['user_password'] = $password;
            $login_data['remember'] = true;
            $user_verify = wp_signon($login_data, true);

            if (is_wp_error($user_verify)) {
                $errors[] = 'Invalid email or password. Please try again!';
            } else {
                wp_set_auth_cookie($user_verify->ID);
                // Mark invite used instead of delete all for that email
                $this->markInviteUsed($invite['record']);
                $page_url = fluent_boards_page_url();
                $boardUrl = $page_url . 'boards/' . $boardId;
                wp_redirect($boardUrl);
                exit;
            }
        }
        status_header(200);
        die("Server received from your browser.");
        //request handlers should die() when they complete their task

    }

    private function makeBoardMember($boardId, $userId)
    {
        $board = Board::find($boardId);
        $board->users()->attach(
            $userId,
            [
                'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                'settings' => maybe_serialize(Constant::BOARD_USER_SETTINGS),
                'preferences' => maybe_serialize(Constant::BOARD_NOTIFICATION_TYPES)
            ]
        );
    }

    private function findInviteMeta($boardId, $hashCode)
    {
        $activeHashCodes = $this->getActiveHashCodes($boardId);
        foreach ($activeHashCodes as $savedHash) {
            $value = maybe_unserialize($savedHash->value);
            if(isset($value['hash']) && $value['hash'] == $hashCode){
                return [
                    'record'     => $savedHash,
                    'email'      => isset($value['email']) ? $value['email'] : '',
                    'issued_at'  => isset($value['issued_at']) ? (int) $value['issued_at'] : 0,
                    'expires_at' => isset($value['expires_at']) ? (int) $value['expires_at'] : 0,
                    'used'       => isset($value['used']) ? (bool) $value['used'] : false,
                ];
            }
        }
        return null;
    }

    private function markInviteUsed($metaRecord)
    {
        $value = maybe_unserialize($metaRecord->value);
        $value['used'] = true;
        $metaRecord->value = $value;
        $metaRecord->save();
    }

    private function generateHash($chars)
    {
        $length = max(16, (int) $chars); // enforce a sensible minimum
        try {
            // Generate enough bytes to cover the requested length when hex-encoded
            $bytes = random_bytes((int) ceil($length / 2));
            $token = bin2hex($bytes); // hex uses [0-9a-f], safe for URLs
        } catch (\Exception $e) {
            // Fallback to WordPress generator (non-ambiguous, no special chars)
            $token = wp_generate_password($length, false, false);
            // normalize to lowercase alnum to avoid surprises
            $token = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $token));
        }
        return substr($token, 0, $length);
    }

    /*
     * Save hash code to database
     */
    private function saveHashByEmail($boardId, $email, $hash)
    {
        $this->deleteHashCode($boardId, $email);

        $issuedAt  = time();
        // default 48 hours, allow filtering
        $lifetime  = (int) apply_filters('fluent_boards/invite_expiry_seconds', 48 * HOUR_IN_SECONDS, $boardId, $email);
        $expiresAt = $issuedAt + max(300, $lifetime); // at least 5 minutes

        $fbs_meta = new Meta();
        $fbs_meta->object_id   = $boardId;
        $fbs_meta->object_type = Constant::OBJECT_TYPE_BOARD;
        $fbs_meta->key         = Constant::BOARD_INVITATION;
        $fbs_meta->value       = [
            'email'      => $email,
            'hash'       => $hash,
            'issued_at'  => $issuedAt,
            'expires_at' => $expiresAt,
            'used'       => false
        ];
        $fbs_meta->save();
    }

    private function deleteHashCode($boardId, $email)
    {
        $activeHashCodes = $this->getActiveHashCodes($boardId);

        foreach ($activeHashCodes as $savedHash) {
            $value = maybe_unserialize($savedHash->value);
            if($value['email'] == $email){
                Meta::where('id', $savedHash->id)->delete();
            }
        }
    }

    private function getActiveHashCodes($boardId)
    {
        return Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_INVITATION)
            ->get();
    }
    

    private function getUserData($userId)
    {
        $currentUser   = wp_get_current_user();
        $gravaterPhoto = fluent_boards_user_avatar($currentUser->user_email, $currentUser->display_name);

        return [
            'display_name' => $currentUser->display_name,
            'photo'        => $gravaterPhoto,
        ];
    }
}
