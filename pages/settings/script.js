const SETTINGS_API = "pages/settings/compose.php";

// CHANGE PASSWORD
function CHANGE_PASSWORD() {
    let CURRENT = $("#CURRENT_PASSWORD").val();
    let NEW_PW  = $("#NEW_PASSWORD").val();
    let CONFIRM = $("#CONFIRM_PASSWORD").val();

    if (!CURRENT) {
        Swal.fire("Error", "Please enter your current password.", "warning");
        return;
    }

    if (NEW_PW.length < 6) {
        Swal.fire("Error", "New password must be at least 6 characters.", "warning");
        return;
    }

    if (NEW_PW !== CONFIRM) {
        Swal.fire("Error", "New passwords do not match.", "warning");
        return;
    }

    if (NEW_PW === CURRENT) {
        Swal.fire("Error", "New password must be different from the current one.", "warning");
        return;
    }

    $("#PASSWORD_BTN").prop("disabled", true);

    let FD = new FormData();
    FD.append("ACTION", "1");
    FD.append("CURRENT_PASSWORD", CURRENT);
    FD.append("NEW_PASSWORD", NEW_PW);

    fetch(SETTINGS_API, { method: "POST", body: FD })
        .then(r => r.json())
        .then(res => {
            $("#PASSWORD_BTN").prop("disabled", false);
            if (res.status === "success") {
                $("#PASSWORD_FORM")[0].reset();
                Swal.fire("Success", res.message, "success").then(() => {
                    // After a forced change (admin reset), continue into the system.
                    if (res.redirect) window.location.href = res.redirect;
                });
            } else {
                Swal.fire("Error", res.message, "error");
            }
        })
        .catch(() => {
            $("#PASSWORD_BTN").prop("disabled", false);
            Swal.fire("Error", "Server error occurred.", "error");
        });
}
