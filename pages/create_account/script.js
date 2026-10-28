const ACCOUNT_API = "pages/create_account/compose.php";


//CREATE ACCOUNT (ACTION 1) / EDIT USER (ACTION 2)
function SUBMIT_BTN() {
    let USER_ID  = parseInt($("#USER_ID").val(), 10) || 0;
    let IS_EDIT  = USER_ID > 0;
    let STATUS   = $("#STATUS").val() || "";
    let LNAME    = $("#LAST_NAME").val().trim();
    let FNAME    = $("#FIRST_NAME").val().trim();
    let MNAME    = $("#MIDDLE_NAME").val().trim();
    let AGE      = $("#AGE").val().trim();
    let MOBILE_NO = $("#MOBILE_NUM").val().trim();
    let ROLE     = $("#ROLE").val();
    let UNAME    = $("#USERNAME").val().trim();
    let PWORD    = $("#PASSWORD").val();
    let CPWORD   = $("#CONFIRM_PASSWORD").val();
    let AVT      = $("#AVATAR").prop('files')[0];

    if (!LNAME || !FNAME) {
        Swal.fire("Invalid", "First name and last name are required.", "warning");
        return;
    }

    if (AGE && (!/^\d+$/.test(AGE) || AGE < 1 || AGE > 120)) {
        Swal.fire("Invalid", "Age must be a number between 1 and 120.", "warning");
        return;
    }

    if (MOBILE_NO && !/^09\d{9}$/.test(MOBILE_NO)) {
        Swal.fire("Invalid", "Phone number must follow the format 09XXXXXXXXX.", "warning");
        return;
    }

    // Role/status selects are disabled when an admin edits their own account — the server keeps them unchanged.
    const allowedRoles = ['Admin', 'User'];
    if (!$("#ROLE").prop("disabled") && (!ROLE || !allowedRoles.includes(ROLE))) {
        Swal.fire("Invalid", "Please assign a valid role.", "warning");
        return;
    }

    if (AVT) {
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if(!allowed.includes(AVT.type)){
            Swal.fire("Invalid", "Only JPG, PNG, and WEBP allowed.", "warning");
            return;
        }
        if(AVT.size > 2 * 1024 * 1024){
             Swal.fire("Invalid", "Avatar too large (max 2MB).", "warning");
             return;
        }
    }

    if (!UNAME) {
        Swal.fire("Error", "Username cannot be empty.", "warning");
        return;
    }

    // Password is required for new accounts; when editing, blank means "keep current password".
    if ((!IS_EDIT || PWORD) && PWORD.length < 6) {
        Swal.fire("Error", "Password must be at least 6 characters.", "warning");
        return;
    }

    if (PWORD !== CPWORD) {
        Swal.fire("Error", "Passwords do not match.", "warning");
        return;
    }

    Swal.fire({
        title: IS_EDIT ? "Save Changes" : "Confirm Submission",
        text: IS_EDIT ? "Update this user's information?" : "Create this account?",
        icon: "question",
        showCancelButton: true,
        confirmButtonText: IS_EDIT ? "Save" : "Submit",
        confirmButtonColor: "#212529"
    }).then((result) => {
        if (!result.isConfirmed) return;

        $("#CREATE_BTN").prop("disabled", true);

        let FD = new FormData();
        FD.append("ACTION",      IS_EDIT ? "2" : "1");
        FD.append("USER_ID",     USER_ID);
        FD.append("STATUS",      STATUS);
        FD.append("LAST_NAME",   LNAME);
        FD.append("FIRST_NAME",  FNAME);
        FD.append("MIDDLE_NAME", MNAME);
        FD.append("AGE",         AGE);
        FD.append("MOBILE_NUM",  MOBILE_NO);
        FD.append("ROLE",        ROLE);
        FD.append("USERNAME",    UNAME);
        FD.append("PASSWORD",    PWORD);
        if (AVT) FD.append("AVATAR", AVT);

        let xhr = new XMLHttpRequest();
        xhr.open("POST", ACCOUNT_API);
        xhr.onreadystatechange = function () {
            if (this.readyState !== 4) return;

            $("#CREATE_BTN").prop("disabled", false);

            if (this.status !== 200) {
                Swal.fire("Error", "Server error occurred.", "error");
                return;
            }

            let res;
            try { res = JSON.parse(this.responseText); }
            catch (e) { Swal.fire("Error", "Unexpected server response.", "error"); return; }

            if (res.status === "success") {
                Swal.fire("Success", res.message, "success").then(() => {
                    window.location.href = "main.php?page=users";
                });
            } else {
                Swal.fire("Error", res.message, "error");
            }
        };
        xhr.send(FD);
    });
}
