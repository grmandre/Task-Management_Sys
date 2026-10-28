const CRTASK_API = "pages/create/compose.php";

// CREATE (ACTION 1) / UPDATE (ACTION 2) TASK
function SAVE_TASK() {
    let TASK_ID   = parseInt($("#TASK_ID").val(), 10) || 0;
    let TITLE     = $("#TITLE").val().trim();
    let DESC      = $("#DESCRIPTION").val().trim();
    let PRIORITY  = $("#PRIORITY").val();
    let STATUS    = $("#STATUS").val() || "";
    let PUB       = $("#PUBLISH").val();
    let EXP       = $("#EXPIRY").val();
    let IS_EDIT   = TASK_ID > 0;

    if (!TITLE){
        Swal.fire("Error", "Task title cannot be empty.", "warning");
        return;
    }

    if (!PRIORITY) {
         Swal.fire("Error", "Please assign a valid priority value.", "warning");
        return;
    }

    if (PUB && EXP && PUB > EXP){
         Swal.fire("Error", "Start date cannot be later than the due date.", "warning");
        return;
    }

    Swal.fire({
        title: IS_EDIT ? "Save Changes" : "Confirm Submission",
        text: IS_EDIT ? "Update this task?" : "Add this task?",
        icon: "question",
        showCancelButton: true,
        confirmButtonText: IS_EDIT ? "Save" : "Submit",
        confirmButtonColor: "#212529"
    }).then((result) => {
        if(!result.isConfirmed) return;

        $("#CRTE_BTN").prop("disabled", true);

        let FD = new FormData();
        FD.append("ACTION", IS_EDIT ? "2" : "1");
        FD.append("TASK_ID", TASK_ID);
        FD.append("TITLE", TITLE);
        FD.append("DESCRIPTION", DESC);
        FD.append("PRIORITY", PRIORITY);
        FD.append("STATUS", STATUS);
        FD.append("PUBLISH", PUB);
        FD.append("EXPIRY", EXP);

        let xhr = new XMLHttpRequest();
        xhr.open("POST", CRTASK_API);
        xhr.onreadystatechange = function () {
            if (this.readyState !== 4) return;

            $("#CRTE_BTN").prop("disabled", false);

            if(this.status !== 200) {
                Swal.fire("Error", "Server error occurred.", "error");
                return;
            }
            let res;
            try { res = JSON.parse(this.responseText);}
            catch (e) { Swal.fire("Error", "Unexpected server response.", "error"); return; }

            if (res.status === "success") {
                Swal.fire("Success", res.message, "success").then(() => {
                    window.location.href = "main.php?page=task";
                });
            } else {
                Swal.fire("Error", res.message, "error");
            }
        };
        xhr.send(FD);
    });
}

// DELETE TASK (ACTION 3) — used by the task list
function DELETE_TASK(TASK_ID, TITLE) {
    Swal.fire({
        title: "Delete Task",
        text: `Delete "${TITLE}"? This cannot be undone.`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Delete",
        confirmButtonColor: "#dc3545"
    }).then((result) => {
        if (!result.isConfirmed) return;

        let FD = new FormData();
        FD.append("ACTION", "3");
        FD.append("TASK_ID", TASK_ID);

        fetch(CRTASK_API, { method: "POST", body: FD })
            .then(r => r.json())
            .then(res => {
                if (res.status === "success") {
                    Swal.fire("Deleted", res.message, "success").then(() => window.location.reload());
                } else {
                    Swal.fire("Error", res.message, "error");
                }
            })
            .catch(() => Swal.fire("Error", "Server error occurred.", "error"));
    });
}
