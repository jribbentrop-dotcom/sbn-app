<?php
/**
 * Template Name: SoulBossa Boutique Repertoire
 */
get_header(); 
$top_votes = function_exists('sbn_get_top_votes') ? sbn_get_top_votes(10) : array();
?>

<div class="sbn-shop-wrapper">
    <header class="sbn-shop-header">
        <h1 class="entry-title">Roadmap & Repertoire</h1>
        <div class="header-divider"></div>
        <p class="sbn-tagline">Professional arrangements in progress. Vote to prioritize.</p>
    </header>

    <?php if (!empty($top_votes)) : ?>
    <section class="sbn-priority-board">
        <h2 class="priority-title"><i class="fa-solid fa-fire"></i> Community Priority Board</h2>
        <div class="priority-grid">
            <?php $rank = 1; foreach ($top_votes as $title => $count) : ?>
            <div class="priority-card">
                <span class="rank-num">#<?php echo $rank++; ?></span>
                <div class="priority-info">
                    <strong><?php echo esc_html($title); ?></strong>
                    <span class="vote-count"><?php echo $count; ?> Votes</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="sbn-shop-filters">
        <div class="sbn-search-box">
            <input type="text" id="sbnSearch" placeholder="Search arrangements...">
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <div class="sbn-dropdowns">
            <select id="sbnFilterStyle"><option value="">All Styles</option><option value="Bossa Nova">Bossa Nova</option><option value="Jazz">Jazz</option><option value="Samba">Samba</option></select>
            <select id="sbnFilterLevel"><option value="">All Difficulties</option><option value="★☆☆☆☆">Basic</option><option value="★★★☆☆">Intermediate</option><option value="★★★★★">Advanced</option></select>
        </div>
    </div>

    <table id="sbnRepertoire" class="sbn-boutique-table" style="width:100%">
        <thead>
            <tr>
                <th>Arrangement</th>
                <th>Style / Artist</th>
                <th>Level</th>
                <th>Format</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<style>
/* --- BOUTIQUE SHOP COLORS & SCALING --- */
:root { --sbn-gold: #b38e5d; --sbn-dark: #333; --sbn-border: #e6e6e6; --sbn-star: #ceba96; }
.sbn-shop-wrapper { max-width: 1200px; margin: 60px auto; padding: 0 30px; font-family: 'Montserrat', sans-serif; }
.sbn-shop-header h1 { font-weight: 300; font-size: 3.2rem; text-align: center; color: var(--sbn-dark); letter-spacing: 2px; }
.header-divider { width: 60px; height: 2px; background: var(--sbn-gold); margin: 20px auto; }
.sbn-tagline { text-align: center; color: #999; font-size: 1.1rem; letter-spacing: 1px; margin-bottom: 50px; }

/* Leaderboard */
.sbn-priority-board { background: #fff; border: 1px solid var(--sbn-gold); padding: 30px; margin-bottom: 60px; border-radius: 2px; }
.priority-title { font-size: 1rem; text-transform: uppercase; letter-spacing: 2px; color: var(--sbn-gold); margin-bottom: 25px; border-bottom: 1px solid #f4f4f4; padding-bottom: 15px; }
.priority-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
.priority-card { display: flex; align-items: center; gap: 15px; padding: 10px; border-bottom: 1px solid #fafafa; }
.rank-num { font-size: 1.6rem; color: var(--sbn-gold); opacity: 0.4; font-weight: 300; }
.vote-count { font-size: 0.8rem; color: #bbb; text-transform: uppercase; font-weight: 600; }

/* Table Scaling */
.sbn-boutique-table td { padding: 35px 15px !important; border-bottom: 1px solid var(--sbn-border); font-size: 1.2rem; }
.sbn-title-cell strong { font-size: 1.4rem; color: var(--sbn-dark); display: block; margin-bottom: 5px; }
.sbn-title-cell small { font-size: 1.1rem; color: var(--sbn-gold); font-weight: 400; }
.stars { color: var(--sbn-star); font-size: 1.1rem; letter-spacing: 2px; }

/* Progress Bars: Red to Green */
.progress-outer { width: 110px; height: 4px; background: #eee; border-radius: 2px; margin-bottom: 5px; }
.progress-inner { height: 100%; border-radius: 2px; }

/* Boutique Vote Button */
.btn-vote { background: transparent; border: 1px solid var(--sbn-gold); color: var(--sbn-gold); padding: 12px 22px; text-transform: uppercase; font-size: 0.8rem; font-weight: 600; letter-spacing: 1px; cursor: pointer; transition: 0.3s; }
.btn-vote:hover { background: var(--sbn-gold); color: #fff; }

/* Filters */
.sbn-shop-filters { display: flex; justify-content: space-between; border-bottom: 1px solid var(--sbn-border); padding-bottom: 30px; margin-bottom: 20px; gap: 20px; }
#sbnSearch { flex: 1; border: 1px solid var(--sbn-border); padding: 15px; background: #fcfcfc; font-size: 1.1rem; }
.sbn-dropdowns select { border: 1px solid var(--sbn-border); padding: 12px 20px; background: #fff; font-size: 1rem; color: #666; }

/* Modal Styling */
.sbn-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); }
.sbn-modal-content { background: #fff; margin: 10% auto; padding: 40px; width: 90%; max-width: 500px; border-radius: 2px; border-top: 5px solid var(--sbn-gold); position: relative; text-align: center; }
.close-modal { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #999; }

#voterEmail { width: 100%; padding: 12px; margin: 20px 0; border: 1px solid #ddd; font-family: 'Montserrat'; }
.donation-pitch { background: var(--sbn-light-gray); padding: 20px; border-radius: 4px; margin-bottom: 25px; }
.donation-pitch p { font-size: 0.85rem; color: #666; margin-bottom: 15px; }

.paypal-btn { display: inline-block; background: #003087; color: #fff; padding: 10px 20px; text-decoration: none; font-size: 0.8rem; font-weight: 700; border-radius: 4px; margin-bottom: 10px; }
.confirm-btn { width: 100%; background: var(--sbn-gold-accent); color: white; padding: 15px; border: none; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; cursor: pointer; }
/* --- BOUTIQUE MODAL STYLING --- */
.sbn-modal-overlay { 
    display: none; position: fixed; z-index: 99999; left: 0; top: 0; 
    width: 100%; height: 100%; background: rgba(255, 255, 255, 0.92); /* Clean white overlay */
    backdrop-filter: blur(8px); 
}

.sbn-boutique-modal { 
    background: #ffffff; margin: 8% auto; padding: 50px; 
    width: 95%; max-width: 550px; border: 1px solid #eee;
    box-shadow: 0 20px 50px rgba(0,0,0,0.08); position: relative;
    border-top: 4px solid #b38e5d;
}

.close-sbn-modal { 
    position: absolute; right: 25px; top: 20px; background: none; 
    border: none; font-size: 32px; color: #ccc; cursor: pointer; transition: 0.3s;
}
.close-sbn-modal:hover { color: #b38e5d; }

.modal-header .sbn-label { 
    text-transform: uppercase; font-size: 0.75rem; letter-spacing: 3px; 
    color: #b38e5d; font-weight: 700; display: block; margin-bottom: 10px;
}
.modal-header h2 { font-size: 2.2rem; font-weight: 300; color: #333; margin: 0; font-family: 'Montserrat', sans-serif; }
.header-divider-gold { width: 40px; height: 2px; background: #b38e5d; margin: 20px auto; }

.modal-intro { font-size: 0.95rem; color: #777; line-height: 1.6; margin-bottom: 30px; }

.input-group { text-align: left; margin-bottom: 25px; }
.input-group label { display: block; font-size: 0.8rem; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; color: #444; }
#voterEmail { width: 100%; padding: 15px; border: 1px solid #ddd; font-size: 1rem; border-radius: 0; outline: none; transition: border-color 0.3s; }
#voterEmail:focus { border-color: #b38e5d; }

.sbn-donation-box { background: #fafafa; padding: 25px; margin-bottom: 30px; border: 1px solid #f0f0f0; }
.sbn-donation-box p { font-size: 0.85rem; color: #888; margin-bottom: 15px; font-style: italic; }

.sbn-paypal-btn { 
    display: inline-block; border: 1px solid #b38e5d; color: #b38e5d; 
    padding: 12px 25px; text-decoration: none; font-size: 0.8rem; 
    text-transform: uppercase; font-weight: 700; letter-spacing: 1px; transition: 0.4s;
}
.sbn-paypal-btn:hover { background: #b38e5d; color: #fff; }

.sbn-confirm-action { 
    width: 100%; background: #333; color: #fff; border: none; 
    padding: 20px; text-transform: uppercase; font-weight: 700; 
    letter-spacing: 2px; cursor: pointer; transition: 0.3s; font-size: 0.9rem;
}
.sbn-confirm-action:hover { background: #b38e5d; }

.modal-footer-note { font-size: 0.7rem; color: #bbb; margin-top: 15px; text-transform: uppercase; }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>



<script>
(function($) {
    $(window).on('load', function() {
        if (!$.fn.DataTable) return;
        
        const table = $('#sbnRepertoire').DataTable({ paging: true, pageLength: 25, dom: 'lrtip', responsive: true });

        function getProgColor(p) {
            const r = p < 50 ? 255 : Math.floor(255 - (p * 2 - 100) * 255 / 100);
            const g = p > 50 ? 255 : Math.floor((p * 2) * 255 / 100);
            return `rgb(${r},${g},0)`;
        }

        $.get('<?php echo content_url("/uploads/repertoire.csv"); ?>?v=<?php echo time(); ?>', function(data) {
            const rows = data.split(/\r?\n/);
            rows.forEach((line, idx) => {
                const cols = line.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);
                if (idx === 0 || cols.length < 6) return;
                
                const [title, comp, artist, style, level, format] = cols.map(c => c.replace(/"/g, "").trim());
                let p = (format === "Leadsheet") ? 80 : (level === "Basic" ? 70 : (level.includes("Intermediate") ? 50 : 30));

                table.row.add([
                    `<div class="sbn-title-cell"><strong>${title}</strong><small>${comp}</small></div>`,
                    `<span style="color:var(--sbn-gold); font-weight:600">${style}</span><br><span style="font-size:1rem; color:#aaa">${artist}</span>`,
                    `<div class="stars">${'★'.repeat(level === "Basic" ? 1 : level.includes("Early") ? 2 : level === "Advanced" ? 5 : level.includes("Late") ? 4 : 3).padEnd(5, '☆')}</div>`,
                    `<span style="color:#bbb; font-size:0.9rem">${format}</span>`,
                    `<div class="progress-outer"><div class="progress-inner" style="width:${p}%; background:${getProgColor(p)}"></div></div><span style="font-size:0.75rem; color:#aaa">${p}% Ready</span>`,
                    `<button class="btn-vote" data-id="${title}">Vote Up</button>`
                ]);
            });
            table.draw(false);
        });

        $('#sbnSearch').on('keyup', function() { table.search(this.value).draw(); });
        $('#sbnFilterStyle').on('change', function() { table.column(1).search(this.value).draw(); });
        $('#sbnFilterLevel').on('change', function() { table.column(2).search(this.value).draw(); });

        $(document).on('click', '.btn-vote', function() {
            const btn = $(this);
            $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                action: 'sbn_vote_song',
                song_id: btn.data('id')
            }, function() {
                btn.html('<i class="fa-solid fa-check"></i>').addClass('voted').prop('disabled', true);
            });
        });
    });
})(jQuery);

// Variable to store which song is being voted on
let activeSong = "";

$(document).on('click', '.btn-vote', function() {
    activeSong = $(this).data('id');
    $('#modalSongTitle').text(activeSong);
    $('#voteModal').fadeIn(200);
});

$('.close-modal').on('click', function() {
    $('#voteModal').fadeOut(200);
});

$('#confirmVoteBtn').on('click', function() {
    const email = $('#voterEmail').val();
    if (!email) { alert("Please enter your email."); return; }

    $(this).html('<i class="fa-solid fa-spinner fa-spin"></i> Processing...');

    fetch('<?php echo esc_url(rest_url("sbn/v1/secure-vote/")); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            email: email,
            song_id: activeSong 
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            $('#voteModal').fadeOut(200);
            alert("Vote recorded! Thank you for supporting the library.");
            // Optionally disable the button on the main table
            $(`.btn-vote[data-id="${activeSong}"]`).html('Voted').addClass('voted').prop('disabled', true);
        } else {
            alert("Error: " + data.message);
        }
    });
});
(function($) {
    $(window).on('load', function() {
        let activeSongTitle = "";

        // 1. CLICK CAPTURE (Fixed with Event Delegation)
        $(document).on('click', '.btn-vote', function(e) {
            e.preventDefault();
            activeSongTitle = $(this).data('id');
            
            // Populate modal and show
            $('#modalSongTitle').text(activeSongTitle);
            
            // Update PayPal link with the song name as a note
            const paypalBase = "https://paypal.me/YOUR_ID/5";
            $('#sbnPaypalLink').attr('href', paypalBase + "?item_name=Support%20Arrangement:%20" + encodeURIComponent(activeSongTitle));
            
            $('.sbn-modal-overlay').fadeIn(300);
        });

        // 2. CLOSE MODAL
        $('.close-sbn-modal, .sbn-modal-overlay').on('click', function(e) {
            if (e.target !== this) return; // Don't close if clicking inside content
            $('.sbn-modal-overlay').fadeOut(300);
        });

        // 3. CONFIRM VOTE (REST API)
        $('#confirmVoteBtn').on('click', function() {
            const email = $('#voterEmail').val();
            const btn = $(this);

            if (!email || !email.includes('@')) {
                alert("Please enter a valid email address.");
                return;
            }

            btn.html('<i class="fa-solid fa-spinner fa-spin"></i> Processing...').prop('disabled', true);

            fetch('<?php echo esc_url(rest_url("sbn/v1/secure-vote/")); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    email: email,
                    song_id: activeSongTitle 
                })
            })
            .then(res => res.json())
            .then(data => {
                $('.sbn-modal-overlay').fadeOut(300);
                alert("Thank you! Your vote for " + activeSongTitle + " has been recorded.");
                
                // Reset button and state
                btn.html('Confirm My Vote').prop('disabled', false);
                $(`.btn-vote[data-id="${activeSongTitle}"]`).html('<i class="fa-solid fa-check"></i> Voted').addClass('voted').prop('disabled', true);
            })
            .catch(err => {
                alert("Communication error. Please try again.");
                btn.html('Confirm My Vote').prop('disabled', false);
            });
        });
    });
})(jQuery);
</script>

<div id="voteModal" class="sbn-modal-overlay">
    <div class="sbn-boutique-modal">
        <button class="close-sbn-modal" aria-label="Close">&times;</button>
        
        <div class="modal-header">
            <span class="sbn-label">Community Request</span>
            <h2 id="modalSongTitle">Arrangement Title</h2>
            <div class="header-divider-gold"></div>
        </div>

        <div class="modal-body">
            <p class="modal-intro">To prioritize this arrangement on the roadmap, please confirm your interest. This helps us gauge demand for the library.</p>
            
            <div class="input-group">
                <label for="voterEmail">Your Email Address</label>
                <input type="email" id="voterEmail" placeholder="musician@example.com">
            </div>

            <div class="sbn-donation-box">
                <p>Arranging complex Bossa Nova scores takes time. If you’d like to support the work voluntarily, it is deeply appreciated.</p>
                <a href="https://paypal.me/YOUR_ID/5" id="sbnPaypalLink" target="_blank" class="sbn-paypal-btn">
                    <i class="fa-brands fa-paypal"></i> Voluntary Support via PayPal
                </a>
            </div>

            <button id="confirmVoteBtn" class="sbn-confirm-action">Confirm My Vote</button>
            <p class="modal-footer-note">Your vote will be recorded instantly.</p>
        </div>
    </div>
</div>

<?php get_footer(); ?>

