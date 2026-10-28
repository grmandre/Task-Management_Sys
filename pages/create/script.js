const CRTASK_API = "pages/create/compose.php";

// Show only the repeat fields that apply to the chosen repeat type
function SYNC_REPEAT_FIELDS() {
    let type = $("#REPEAT_TYPE").val() || "none";
    $(".repeat-only").toggle(type !== "none");
    $(".repeat-weekly").toggle(type === "weekly");
    $(".repeat-monthly").toggle(type === "monthly");
}

// Due time and reminder need a due date. Values are kept (e.g. from a template) and
// only take effect once a due date is set.
function SYNC_DUE_FIELDS() {
    $("#DUE_TIME, #REMIND_BEFORE").prop("disabled", !$("#EXPIRY").val());
}

function ADD_CHECKLIST_ROW() {
    let input = $("#NEW_ITEM");
    let title = input.val().trim();
    if (!title) return;

    let row = $(`
        <div class="checklist-row d-flex align-items-center gap-2 mb-2" data-id="0">
            <input type="checkbox" class="form-check-input mt-0 item-done" aria-label="Done">
            <input type="text" class="form-control form-control-sm item-title" maxlength="255">
            <button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove item">
                <i class="lni lni-trash-3"></i>
            </button>
        </div>`);
    row.find(".item-title").val(title);
    row.find("button").on("click", () => row.remove());
    $("#CHECKLIST").append(row);
    input.val("").focus();
}

function EXP_SET() {
    return !!$("#EXPIRY").val();
}

function CHECKED_VALUES(container) {
    return $(container).find("input:checked").map((_, el) => el.value).get().join(",");
}

// CREATE (ACTION 1) / UPDATE (ACTION 2) TASK
function SAVE_TASK() {
    let TASK_ID   = parseInt($("#TASK_ID").val(), 10) || 0;
    let TITLE     = $("#TITLE").val().trim();
    let DESC      = $("#DESCRIPTION").val().trim();
    let PRIORITY  = $("#PRIORITY").val();
    let STATUS    = $("#STATUS").val() || "";
    let PUB       = $("#PUBLISH").val();
    let EXP       = $("#EXPIRY").val();
    let DUE_TIME  = EXP_SET() ? ($("#DUE_TIME").val() || "") : "";
    let REMIND    = EXP_SET() ? ($("#REMIND_BEFORE").val() || "") : "";
    let REPEAT    = $("#REPEAT_TYPE").val() || "none";
    let R_UNTIL   = $("#REPEAT_UNTIL").val() || "";
    let IS_EDIT   = TASK_ID > 0;

    let R_DAYS = REPEAT === "weekly"  ? CHECKED_VALUES("#WEEKDAY_PICKER")
               : REPEAT === "monthly" ? CHECKED_VALUES("#MONTHDAY_PICKER") : "";

    // Include a typed-but-not-added checklist item rather than silently dropping it
    if ($("#NEW_ITEM").val().trim()) ADD_CHECKLIST_ROW();

    let CHECKLIST = $("#CHECKLIST .checklist-row").map((_, row) => ({
        id:    parseInt($(row).data("id"), 10) || 0,
        title: $(row).find(".item-title").val().trim(),
        done:  $(row).find(".item-done").is(":checked") ? 1 : 0
    })).get().filter(i => i.title);

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

    if (REPEAT === "weekly" && !R_DAYS) {
        Swal.fire("Error", "Pick at least one day of the week for a weekly task.", "warning");
        return;
    }

    if (REPEAT === "monthly" && !R_DAYS) {
        Swal.fire("Error", "Pick at least one day of the month for a monthly task.", "warning");
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
        FD.append("DUE_TIME", DUE_TIME);
        FD.append("REMIND_BEFORE", REMIND);
        FD.append("REPEAT_TYPE", REPEAT);
        FD.append("REPEAT_DAYS", R_DAYS);
        FD.append("REPEAT_UNTIL", R_UNTIL);
        FD.append("CHECKLIST", JSON.stringify(CHECKLIST));
        if ($("#SAVE_TEMPLATE").is(":checked")) {
            FD.append("SAVE_TEMPLATE", "1");
            FD.append("TEMPLATE_NAME", $("#TEMPLATE_NAME").val().trim());
        }

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
                    window.location.href = "main.php?page=task_view&id=" + res.task_id;
                });
            } else {
                Swal.fire("Error", res.message, "error");
            }
        };
        xhr.send(FD);
    });
}

// Small helper for the one-shot JSON actions below
function TASK_ACTION(fields) {
    let FD = new FormData();
    Object.entries(fields).forEach(([k, v]) => FD.append(k, v));
    return fetch(CRTASK_API, { method: "POST", body: FD })
        .then(r => r.json())
        .catch(() => ({ status: "error", message: "Server error occurred." }));
}

// DELETE TASK (ACTION 3) — used by the task list and the task page
function DELETE_TASK(TASK_ID, TITLE, IS_SERIES, REDIRECT) {
    Swal.fire({
        title: "Delete Task",
        text: IS_SERIES
            ? `Delete "${TITLE}"? This also stops the task from repeating. Copies already created stay.`
            : `Delete "${TITLE}"? This cannot be undone.`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Delete",
        confirmButtonColor: "#dc3545"
    }).then((result) => {
        if (!result.isConfirmed) return;

        TASK_ACTION({ ACTION: 3, TASK_ID }).then(res => {
            if (res.status === "success") {
                Swal.fire("Deleted", res.message, "success").then(() => {
                    if (REDIRECT) window.location.href = REDIRECT;
                    else window.location.reload();
                });
            } else {
                Swal.fire("Error", res.message, "error");
            }
        });
    });
}

// SAVE AS TEMPLATE (ACTION 8)
function SAVE_AS_TEMPLATE(TASK_ID, TITLE) {
    Swal.fire({
        title: "Save as Template",
        input: "text",
        inputValue: TITLE,
        inputLabel: "Template name",
        inputAttributes: { maxlength: 100 },
        showCancelButton: true,
        confirmButtonText: "Save",
        confirmButtonColor: "#212529",
        inputValidator: v => !v.trim() && "Please enter a name."
    }).then((result) => {
        if (!result.isConfirmed) return;
        TASK_ACTION({ ACTION: 8, TASK_ID, TEMPLATE_NAME: result.value.trim() }).then(res => {
            Swal.fire(res.status === "success" ? "Saved" : "Error", res.message, res.status === "success" ? "success" : "error");
        });
    });
}

// DELETE TEMPLATE (ACTION 9)
function DELETE_TEMPLATE(TEMPLATE_ID) {
    Swal.fire({
        title: "Delete Template",
        text: "Delete this template? Tasks already made from it are not affected.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Delete",
        confirmButtonColor: "#dc3545"
    }).then((result) => {
        if (!result.isConfirmed) return;
        TASK_ACTION({ ACTION: 9, TEMPLATE_ID }).then(res => {
            if (res.status === "success") {
                Swal.fire("Deleted", res.message, "success").then(() => window.location.href = "main.php?page=tasks_create");
            } else {
                Swal.fire("Error", res.message, "error");
            }
        });
    });
}
