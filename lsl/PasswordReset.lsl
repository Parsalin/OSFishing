// ============================================================
// PASSWORD RESET KIOSK
// ============================================================
// Touch to reset your fishing game password.
// Uses your avatar UUID as identity verification.
// No email needed — the grid authenticates you.
// ============================================================

string gApiUrl = "https://sp.wa.darkheartsos.net/fishing/api/";

key     gHttpReq    = NULL_KEY;
string  gHttpAction = "";
key     gUser       = NULL_KEY;
string  gUserName   = "";
integer gTextCh     = 0;
integer gTextHandle = 0;
integer gDialogCh   = 0;
integer gDialogHandle = 0;
string  gNewPassword = "";
string  gStep       = "";  // "confirm", "password", "verify"

default {
    state_entry() {
        llSetText("🔑 Password Reset\nTouch to reset your\nfishing game password", <0.8, 0.6, 0.2>, 1.0);
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);

        // Only one user at a time
        if (gUser != NULL_KEY && gUser != who) {
            llRegionSayTo(who, 0, "Someone else is using this kiosk. Please wait.");
            return;
        }

        gUser = who;
        gUserName = llGetDisplayName(who);
        gStep = "confirm";

        // First verify they have an account
        gHttpAction = "check";
        gHttpReq = llHTTPRequest(gApiUrl, [
            HTTP_METHOD, "POST",
            HTTP_MIMETYPE, "application/x-www-form-urlencoded",
            HTTP_BODY_MAXLENGTH, 4096
        ], "action=check_account&uuid=" + llEscapeURL((string)who));
    }

    listen(integer ch, string name, key id, string msg) {
        if (id != gUser) return;

        // ── Confirm reset ──
        if (gStep == "confirm") {
            if (gDialogHandle) { llListenRemove(gDialogHandle); gDialogHandle = 0; }
            if (msg == "Cancel") {
                llRegionSayTo(gUser, 0, "Password reset cancelled.");
                gUser = NULL_KEY;
                gStep = "";
                return;
            }
            if (msg == "Reset") {
                gStep = "password";
                gTextCh = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gUser, "");
                llTextBox(gUser, "🔑 Enter your NEW password:\n\n(Minimum 6 characters)", gTextCh);
            }
            return;
        }

        // ── New password entered ──
        if (gStep == "password") {
            if (gTextHandle) { llListenRemove(gTextHandle); gTextHandle = 0; }

            if (llStringLength(msg) < 6) {
                llRegionSayTo(gUser, 0, "Password must be at least 6 characters. Try again.");
                gStep = "password";
                gTextCh = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gUser, "");
                llTextBox(gUser, "🔑 Password too short!\n\nEnter a password with at least 6 characters:", gTextCh);
                return;
            }

            gNewPassword = msg;
            gStep = "verify";
            gTextCh = -1 - (integer)llFrand(999999.0);
            gTextHandle = llListen(gTextCh, "", gUser, "");
            llTextBox(gUser, "🔑 Confirm your new password:\n\n(Type it again to verify)", gTextCh);
            return;
        }

        // ── Verify password ──
        if (gStep == "verify") {
            if (gTextHandle) { llListenRemove(gTextHandle); gTextHandle = 0; }

            if (msg != gNewPassword) {
                llRegionSayTo(gUser, 0, "Passwords don't match. Starting over.");
                gNewPassword = "";
                gStep = "password";
                gTextCh = -1 - (integer)llFrand(999999.0);
                gTextHandle = llListen(gTextCh, "", gUser, "");
                llTextBox(gUser, "🔑 Passwords didn't match.\n\nEnter your NEW password:", gTextCh);
                return;
            }

            // Passwords match — send to server
            llRegionSayTo(gUser, 0, "Updating password...");
            gHttpAction = "reset";
            gHttpReq = llHTTPRequest(gApiUrl, [
                HTTP_METHOD, "POST",
                HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                HTTP_BODY_MAXLENGTH, 4096
            ], "action=password_reset&uuid=" + llEscapeURL((string)gUser) +
               "&new_password=" + llEscapeURL(gNewPassword));
            gNewPassword = "";  // Clear immediately
            return;
        }
    }

    http_response(key req, integer status, list meta, string body) {
        if (req != gHttpReq) return;

        string ok = llJsonGetValue(body, ["success"]);

        if (gHttpAction == "check") {
            if (ok != "true" && ok != "1" && ok != JSON_TRUE) {
                llRegionSayTo(gUser, 0, "No fishing account found for your avatar. Register on the web portal first.");
                gUser = NULL_KEY;
                gStep = "";
                return;
            }

            // Account exists — confirm reset
            string playerName = llJsonGetValue(body, ["display_name"]);
            if (playerName == JSON_INVALID) playerName = gUserName;

            gStep = "confirm";
            gDialogCh = -1 - (integer)llFrand(999999.0);
            gDialogHandle = llListen(gDialogCh, "", gUser, "");
            llDialog(gUser,
                "🔑 Password Reset\n\nAccount: " + playerName +
                "\n\nAre you sure you want to reset your password?",
                ["Reset", "Cancel"], gDialogCh);
        }
        else if (gHttpAction == "reset") {
            if (ok == "true" || ok == "1" || ok == JSON_TRUE) {
                llRegionSayTo(gUser, 0, "✅ Password updated! You can now log in on the web portal with your new password.");
            } else {
                string err = llJsonGetValue(body, ["error"]);
                if (err == JSON_INVALID) err = "Failed to update password.";
                llRegionSayTo(gUser, 0, "❌ " + err);
            }
            gUser = NULL_KEY;
            gStep = "";
        }
    }

    on_rez(integer p) { llResetScript(); }
}
