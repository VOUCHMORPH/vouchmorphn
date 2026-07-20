async function submitClaim(swapReference) {
    const pin = document.getElementById('claimPin').value.trim();
    const destType = document.getElementById('claimDestType').value;
    if (!pin) { showMessage('Enter your claim PIN.', 'warning'); return; }
    
    // ============================================================
    // FIX: Use identity_type + identity_value instead of swap_reference
    // This claims ALL holds at once
    // ============================================================
    const claim = pendingClaims.find(c => c.swap_reference === swapReference);
    if (!claim) {
        showMessage('Claim not found.', 'error');
        return;
    }
    
    const payload = {
        identity_type: claim.identity_type,
        identity_value: claim.identity_value,
        pin: pin,
        destination_type: destType
    };
    
    if (destType === 'DEPOSIT') {
        payload.destination_institution = document.getElementById('claimDestInst').value;
        payload.destination_identifier = document.getElementById('claimDestIdentifier').value.trim();
        if (!payload.destination_institution || !payload.destination_identifier) {
            showMessage('Select a destination institution and enter an account/wallet number.', 'warning');
            return;
        }
    }
    
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', payload);
    if (!result.ok) {
        showMessage('Claim failed: ' + result.error, 'error');
        return;
    }
    
    closeModal();
    showMessage(result.body.message || 'Funds claimed successfully!', 'success');
    checkPendingClaims();
}
