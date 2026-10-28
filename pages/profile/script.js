function PREVIEW_AVATAR(input) {
    if (!input.files || !input.files[0]) return;
    let file = input.files[0];
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        Swal.fire("Invalid", "Only JPG, PNG, and WEBP allowed.", "warning");
        input.value = "";
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        Swal.fire("Invalid", "Avatar must be under 2 MB.", "warning");
        input.value = "";
        return;
    }
    let reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById("AVATAR_PREVIEW").src = e.target.result;
    };
    reader.readAsDataURL(file);
}