<!-- Lightbox -->
<div id="lightbox" onclick="closeLightbox()" 
     style="display:none; position:fixed; z-index:9999; 
            padding-top:50px; left:0; top:0; width:100%; height:100%;
            background-color:rgba(0,0,0,0.9); text-align:center;">

    <span style="position:absolute; top:20px; right:40px; 
                 color:white; font-size:40px; cursor:pointer;">&times;</span>

    <img id="lightbox-img" 
         style="max-width:90%; max-height:90%;">
</div>



</div>
<script src="js/bootstrap.bundle.min.js"></script>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->


<script>
function openLightbox(src) {
    document.getElementById("lightbox").style.display = "block";
    document.getElementById("lightbox-img").src = src;
}

function closeLightbox() {
    document.getElementById("lightbox").style.display = "none";
}
</script>




</body>
</html>
