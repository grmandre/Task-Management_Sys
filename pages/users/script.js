const USERS_API = "pages/create_account/compose.php";

// Filter the users table by any visible text (name, username, phone, role, status)
function FILTER_USERS(query) {
    let q = query.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll("#USER_TABLE tr:not(#USER_EMPTY)").forEach(row => {
        let match = row.textContent.toLowerCase().includes(q);
        row.classList.toggle("d-none", !match);
        if (match) shown++;
    });
    document.getElementById("USER_EMPTY").classList.toggle("d-none", shown > 0);
}

// RESET PASSWORD (ACTION 3) — show the temporary password once so the admin can hand it over
function RESET_PASSWORD(USER_ID, NAME) {
    Swal.fire({
        title: "Reset Password",
        text: `Reset the password for ${NAME}? Their current password will stop working immediately.`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Reset",
        confirmButtonColor: "#dc3545"
    }).then((result) => {
        if (!result.isConfirmed) return;

        let FD = new FormData();
        FD.append("ACTION", "3");
        FD.append("USER_ID", USER_ID);

        fetch(USERS_API, { method: "POST", body: FD })
            .then(r => r.json())
            .then(res => {
                if (res.status !== "success") {
                    Swal.fire("Error", res.message, "error");
                    return;
                }
                SHOW_TEMP_PASSWORD(res.username, res.password);
            })
            .catch(() => Swal.fire("Error", "Server error occurred.", "error"));
    });
}

function SHOW_TEMP_PASSWORD(USERNAME, PASSWORD) {
    let esc = s => $("<div>").text(s).html();

    Swal.fire({
        title: "Password Reset",
        icon: "success",
        html: `
            <p class="small text-muted mb-3">Give these details to the user. They will be asked to set a new password when they log in.</p>
            <div class="bg-light rounded-3 p-3 text-start">
                <div class="small text-muted">Username</div>
                <div class="fw-semibold mb-2">${esc(USERNAME)}</div>
                <div class="small text-muted">Temporary Password</div>
                <div class="d-flex align-items-center gap-2">
                    <code id="TEMP_PW" class="fs-5 text-dark flex-grow-1">${esc(PASSWORD)}</code>
                    <button type="button" id="COPY_PW" class="btn btn-sm btn-outline-dark">
                        <i class="lni lni-clipboard me-1"></i> Copy
                    </button>
                </div>
            </div>
            <p class="small text-danger mt-3 mb-0">This password won't be shown again.</p>`,
        confirmButtonText: "Done",
        confirmButtonColor: "#212529",
        allowOutsideClick: false,
        didOpen: () => {
            document.getElementById("COPY_PW").addEventListener("click", function () {
                navigator.clipboard.writeText(PASSWORD).then(() => {
                    this.innerHTML = '<i class="lni lni-check-circle-1 me-1"></i> Copied';
                });
            });
        }
    });
}
