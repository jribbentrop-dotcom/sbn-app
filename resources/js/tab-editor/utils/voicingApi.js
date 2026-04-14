export async function fetchVoicings(params) {
    const searchParams = new URLSearchParams({
        root: params.root || '',
        quality: params.quality || '',
        extension: params.extension || '',
        inversion: params.inversion || 'all',
        voicing_category: params.voicingcategory || 'all',
        root_string: params.rootstring || 'all',
        bass_note: params.bassnote || '',
    });
    
    // Safely get CSRF token
    let csrfToken = '';
    const tokenMeta = document.querySelector('meta[name=csrf-token]');
    if (tokenMeta) {
        csrfToken = tokenMeta.content;
    }

    const resp = await fetch('/api/admin/leadsheets/search-voicings-advanced?' + searchParams.toString(), {
        headers: { 
            'X-CSRF-TOKEN': csrfToken, 
            'Accept': 'application/json' 
        }
    });
    
    return await resp.json();
}
