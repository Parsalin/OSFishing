// ============================================================
// ACCOUNT RECOVERY TERMINAL (admin object) — replaces PasswordReset
// ============================================================
// Place in-world (NOT worn), somewhere it will stay.
//
// A player touches it and gets a one-time login link (llLoadURL, shown only
// to them) that lets them set a new password without the old one. The server
// only answers for avatars linked to an account ON THIS GRID, at most once
// every 10 minutes per account.
//
// Setup: rez it (or reset it) as an admin — your avatar must be linked to an
// admin account on the website. It opens a setup link; approve it there.
// The token lives ONLY in this script's memory: a reset, re-rez or owner
// change forgets it. The server refuses the token from any other object or
// internet address, so a copy (even with its memory intact) is useless. It
// checks in every 30 minutes with its region and position; the website lists
// live terminals on the login page.
//
// Never call osGetGridName()/osGetGridLoginURI(): a denied OSSL call halts
// the script. detectGrid() uses the sim hostname only.
// ============================================================

string API_URL = "https://osfishing.flamesfall.net/api/";
list   HTTP_OPTS = [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded",
                    HTTP_BODY_MAXLENGTH, 4096];

float  POLL_SECONDS  = 5.0;      // while waiting for approval
float  HELLO_SECONDS = 1800.0;   // check-in

key     gOwner;
string  gToken;          // memory only — never linkset data or a notecard
string  gPairCode;
integer gPairUntil;
integer gNextHelloAt;
integer gPaused;         // server refused our address; waiting for the admin

key     gPairReq;
key     gPollReq;
key     gHelloReq;
list    gRecoveryReqs;   // [request key, avatar key, ...]

string detectGrid() {
    string h = llGetSimulatorHostname();
    if (h == "") h = llGetEnv("simulator_hostname");
    if (h == "") return "unknown";
    if (llSubStringIndex(h, ":") == -1) h += ":8002";
    return h;
}

string location() {
    return "&region=" + llEscapeURL(llGetRegionName())
         + "&position=" + llEscapeURL((string)llGetPos())
         + "&grid_name=" + llEscapeURL(detectGrid());
}

key post(string action, string extra) {
    return llHTTPRequest(API_URL, HTTP_OPTS,
        "action=" + action + "&uuid=" + (string)gOwner
        + "&token=" + gToken + extra);
}

showStatus(string text, vector colour) {
    llSetText(text, colour, 1.0);
}

ready() {
    gPaused = FALSE;
    showStatus("Account Recovery\nForgot your OSFishing password?\nTouch me for a one-time login link.", <0.6, 1, 0.6>);
}

startPairing() {
    gToken = ""; gPairCode = "";
    showStatus("Account Recovery\nSetting up...", <1, 1, 0>);
    gPairReq = post("aobj_pair_request", "&type=recovery&name=" + llEscapeURL(llGetObjectName()) + location());
}

sendHello() {
    if (gToken == "") return;
    gNextHelloAt = llGetUnixTime() + (integer)HELLO_SECONDS;
    gHelloReq = post("aobj_hello", location());
}

forget(string why) {
    gToken = ""; gPairCode = "";
    llSetTimerEvent(0.0);
    showStatus("Account Recovery\nOut of order", <1, 0.4, 0.4>);
    llOwnerSay("[Recovery] " + why + " Touch me to set me up again.");
}

// Errors any authenticated reply can carry. TRUE = handled.
integer authError(string err) {
    if (err == "unknown_token" || err == "revoked") {
        forget("The server no longer accepts me (" + err + ").");
        return TRUE;
    }
    if (err == "address_changed") {
        gPaused = TRUE;
        showStatus("Account Recovery\nTemporarily unavailable", <1, 0.6, 0.2>);
        llOwnerSay("[Recovery] My sim's address changed. Accept it on the website's admin Objects tab.");
        gNextHelloAt = llGetUnixTime() + 300;   // try again in 5 minutes
        return TRUE;
    }
    return FALSE;
}

// Presence test: llJsonGetValue returns JSON_INVALID for a missing key.
string jget(string body, string k) {
    string v = llJsonGetValue(body, [k]);
    if (v == JSON_INVALID) return "";
    return v;
}

default {
    state_entry() {
        gOwner = llGetOwner();
        gRecoveryReqs = [];
        startPairing();
    }

    on_rez(integer param) {
        llResetScript();   // a rez is a copy or a move: forget the token
    }

    changed(integer change) {
        if (change & CHANGED_OWNER) llResetScript();
        if (change & CHANGED_REGION_RESTART) sendHello();
    }

    touch_start(integer n) {
        key who = llDetectedKey(0);
        if (gToken == "") {
            if (who == gOwner && gPairCode == "") startPairing();
            else llRegionSayTo(who, 0, "This Account Recovery terminal is out of order. Please ask an admin.");
            return;
        }
        if (gPaused) {
            llRegionSayTo(who, 0, "This Account Recovery terminal is temporarily unavailable. Please try again later or ask an admin.");
            return;
        }
        if (llListFindList(gRecoveryReqs, [who]) != -1) return;   // already asking for them
        llRegionSayTo(who, 0, "Checking your account...");
        key req = post("aobj_recovery",
            "&avatar=" + (string)who + "&avatar_name=" + llEscapeURL(llGetDisplayName(who)) + location());
        gRecoveryReqs += [req, who];
    }

    timer() {
        integer now = llGetUnixTime();
        if (gPairCode != "") {
            if (now > gPairUntil) {
                gPairCode = "";
                forget("The setup link expired.");
                return;
            }
            gPollReq = post("aobj_pair_status", "&code=" + gPairCode);
            return;
        }
        if (gToken != "" && now >= gNextHelloAt) sendHello();
    }

    http_response(key id, integer status, list meta, string body) {
        string err = jget(body, "error");

        integer r = llListFindList(gRecoveryReqs, [id]);
        if (r != -1) {
            key who = llList2Key(gRecoveryReqs, r + 1);
            gRecoveryReqs = llDeleteSubList(gRecoveryReqs, r, r + 1);
            string msg = jget(body, "msg");
            string url = jget(body, "url");
            if (status == 200 && url != "") {
                // llLoadURL is shown to this avatar only, so nobody nearby sees the link.
                llLoadURL(who, msg, url);
                return;
            }
            if (authError(err)) {
                llRegionSayTo(who, 0, "This Account Recovery terminal is temporarily unavailable. Please ask an admin.");
                return;
            }
            if (msg == "") msg = "Sorry, that didn't work (" + err + "). Please ask an admin.";
            llRegionSayTo(who, 0, msg);
            return;
        }

        if (id == gPairReq) {
            string code = jget(body, "code");
            if (status != 200 || code == "") {
                if (err == "not_admin") forget("My owner isn't linked to an admin account on the OSFishing website.");
                else forget("Setup failed (" + (string)status + " " + err + ").");
                return;
            }
            gPairCode  = code;
            gPairUntil = llGetUnixTime() + 900;
            string url = jget(body, "url");
            showStatus("Account Recovery\nWaiting for an admin to approve me", <1, 1, 0>);
            llLoadURL(gOwner, "Approve this Account Recovery terminal on the OSFishing website.", url);
            llOwnerSay("[Recovery] Approve me here (you have 15 minutes): " + url);
            llSetTimerEvent(POLL_SECONDS);
            return;
        }

        if (id == gPollReq) {
            string st = jget(body, "status");
            if (st == "active") {
                gToken    = jget(body, "token");
                gPairCode = "";
                llOwnerSay("[Recovery] Set up. Players can use me now.");
                llSetTimerEvent(60.0);
                ready();
                sendHello();
            }
            else if (st == "rejected" || st == "expired") {
                gPairCode = "";
                forget("Setup was " + st + ".");
            }
            return;
        }

        if (id == gHelloReq) {
            if (status == 200) { if (gPaused) ready(); return; }
            authError(err);
        }
    }
}
