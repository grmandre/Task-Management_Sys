// Task details page — uses TASK_ACTION() from pages/create/script.js

function SET_STATUS(TASK_ID, STATUS) {
    fetch("handlers/toggle_task_status_handler.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `task_id=${TASK_ID}&status=${encodeURIComponent(STATUS)}`
    })
        .then(r => r.json())
        .then(res => {
            if (res.ok) window.location.reload();
            else Swal.fire("Error", "Could not update the status.", "error");
        })
        .catch(() => Swal.fire("Error", "Server error occurred.", "error"));
}

function TOGGLE_ITEM(ITEM_ID, DONE) {
    let label = $(`label[for="ITEM_${ITEM_ID}"]`);
    label.toggleClass("text-decoration-line-through text-muted", DONE);

    TASK_ACTION({ ACTION: 4, ITEM_ID, DONE: DONE ? 1 : 0 }).then(res => {
        if (res.status !== "success") {
            Swal.fire("Error", res.message, "error");
            return;
        }
        let total = parseInt(res.total, 10), done = parseInt(res.done, 10);
        $("#CHECKLIST_COUNT").text(`${done} of ${total} done`);
        $("#CHECKLIST_BAR").css("width", (total ? Math.round(done / total * 100) : 0) + "%");
    });
}

function ADD_ITEM(TASK_ID) {
    let TITLE = $("#NEW_ITEM").val().trim();
    if (!TITLE) return;
    TASK_ACTION({ ACTION: 5, TASK_ID, TITLE }).then(res => {
        if (res.status === "success") window.location.reload();
        else Swal.fire("Error", res.message, "error");
    });
}

function ADD_NOTE(TASK_ID) {
    let NOTE = $("#NEW_NOTE").val().trim();
    if (!NOTE) {
        Swal.fire("Error", "Note cannot be empty.", "warning");
        return;
    }
    TASK_ACTION({ ACTION: 6, TASK_ID, NOTE }).then(res => {
        if (res.status === "success") window.location.reload();
        else Swal.fire("Error", res.message, "error");
    });
}

function DELETE_NOTE(NOTE_ID) {
    Swal.fire({
        title: "Delete Note",
        text: "Delete this note?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Delete",
        confirmButtonColor: "#dc3545"
    }).then((result) => {
        if (!result.isConfirmed) return;
        TASK_ACTION({ ACTION: 7, NOTE_ID }).then(res => {
            if (res.status === "success") window.location.reload();
            else Swal.fire("Error", res.message, "error");
        });
    });
}
