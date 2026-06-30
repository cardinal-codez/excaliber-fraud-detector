import { render, useState, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';


// ── API Helper ─────────────────────────────────
const api = {
    get: ( endpoint ) => fetch( `${aifdData.apiUrl}${endpoint}`, {
        headers: { 'X-WP-Nonce': aifdData.nonce }
    }).then( r => r.json() ),

    post: ( endpoint, body = {} ) => fetch( `${aifdData.apiUrl}${endpoint}`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': aifdData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify( body )
    }).then( r => r.json() )
};

// ── Score Bar ──────────────────────────────────
const ScoreBar = ({ score }) => {
    const color = score >= 70 ? '#ef4444' : score >= 40 ? '#f59e0b' : '#10b981';
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <div style={{ flex: 1, height: '6px', background: '#f1f5f9', borderRadius: '3px' }}>
                <div style={{
                    width: `${score}%`, height: '6px',
                    background: color, borderRadius: '3px',
                    transition: 'width 0.3s'
                }} />
            </div>
            <span style={{ fontFamily: 'monospace', fontSize: '12px', fontWeight: '600', color, minWidth: '28px' }}>
                {score}
            </span>
        </div>
    );
};

// ── Decision Badge ─────────────────────────────
const Badge = ({ decision }) => {
    const map = {
        BLOCK:          { bg: '#fef2f2', color: '#ef4444', border: '#fecaca', label: '🚫 Block' },
        REVIEW:         { bg: '#fffbeb', color: '#f59e0b', border: '#fde68a', label: '⚠️ Review' },
        ALLOW:          { bg: '#f0fdf4', color: '#10b981', border: '#bbf7d0', label: '✅ Allow' },
        APPROVED:       { bg: '#f0fdf4', color: '#10b981', border: '#bbf7d0', label: '✅ Approved' },
        BLOCKED:        { bg: '#fef2f2', color: '#ef4444', border: '#fecaca', label: '🚫 Blocked' },
    };
    const s = map[decision] || map.REVIEW;
    return (
        <span style={{
            padding: '3px 10px', borderRadius: '20px',
            fontSize: '11px', fontWeight: '600',
            background: s.bg, color: s.color,
            border: `1px solid ${s.border}`
        }}>
            {s.label}
        </span>
    );
};

// ── Review Status Badge ────────────────────────
const ReviewBadge = ({ status }) => {
    const map = {
        PENDING:          { bg: '#eff6ff', color: '#3b82f6', label: '⏳ Pending Review' },
        CONFIRMED_FRAUD:  { bg: '#fef2f2', color: '#ef4444', label: '🚨 Confirmed Fraud' },
        FALSE_POSITIVE:   { bg: '#f0fdf4', color: '#10b981', label: '✅ False Positive' },
    };
    const s = map[status] || map.PENDING;
    return (
        <span style={{
            padding: '3px 10px', borderRadius: '20px',
            fontSize: '10px', fontWeight: '600',
            background: s.bg, color: s.color,
        }}>
            {s.label}
        </span>
    );
};

// ── Stat Card ──────────────────────────────────
const StatCard = ({ value, label, color, prefix = '' }) => (
    <div style={{
        background: '#fff', border: '1px solid #e2e8f0',
        borderRadius: '12px', padding: '16px 18px',
        borderTop: `3px solid ${color}`, flex: 1
    }}>
        <div style={{ fontSize: '28px', fontWeight: '700', color }}>
            {prefix}{typeof value === 'number' ? value.toLocaleString() : value}
        </div>
        <div style={{ fontSize: '11px', color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '.05em', marginTop: '4px' }}>
            {label}
        </div>
    </div>
);

// ── Feedback Buttons ───────────────────────────
const FeedbackButtons = ({ order, onAction }) => {

    const [acting, setActing] = useState( false );
    const [done, setDone]     = useState( false );

    const status = order.review_status;

    if ( status === 'CONFIRMED_FRAUD' ) {
        return <ReviewBadge status="CONFIRMED_FRAUD" />;
    }

    if ( status === 'FALSE_POSITIVE' ) {
        return <ReviewBadge status="FALSE_POSITIVE" />;
    }

    if ( done ) {
        return <ReviewBadge status={done} />;
    }

    const handleConfirm = async () => {
        if ( ! confirm( `Confirm Order #${order.id} as fraud? This will save the pattern to your fraud database.` ) ) return;
        setActing( true );
        await api.post( `order/${order.id}/confirm-fraud` );
        setDone( 'CONFIRMED_FRAUD' );
        setActing( false );
        onAction();
    };

    const handleOverride = async () => {
        if ( ! confirm( `Mark Order #${order.id} as legitimate? This will release the order and adjust your fraud signal weights.` ) ) return;
        setActing( true );
        await api.post( `order/${order.id}/override` );
        setDone( 'FALSE_POSITIVE' );
        setActing( false );
        onAction();
    };

    if ( acting ) return <Spinner />;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
            <button onClick={handleConfirm} style={{
                padding: '4px 10px', borderRadius: '6px',
                fontSize: '11px', fontWeight: '600', cursor: 'pointer',
                border: '1px solid #fecaca', background: '#fef2f2', color: '#ef4444',
                whiteSpace: 'nowrap'
            }}>
                🚨 Confirm Fraud
            </button>
            <button onClick={handleOverride} style={{
                padding: '4px 10px', borderRadius: '6px',
                fontSize: '11px', fontWeight: '600', cursor: 'pointer',
                border: '1px solid #bbf7d0', background: '#f0fdf4', color: '#10b981',
                whiteSpace: 'nowrap'
            }}>
                ✅ Not Fraud
            </button>
        </div>
    );
};

const WeightsPanel = ({ weights, onReset, resetting }) => {

    const tierColors = { 1: '#ef4444', 2: '#f59e0b', 3: '#6366f1' };
    const tierLabels = { 1: 'Tier 1 — Critical', 2: 'Tier 2 — Strong', 3: 'Tier 3 — Contextual' };

    const signalLabels = {
        card_testing_pattern:   'Card Testing Pattern',
        disposable_email:       'Disposable Email',
        high_velocity:          'High Velocity',
        address_mismatch:       'Address Mismatch',
        freight_forwarder:      'Freight Forwarder',
        chargeback_history:     'Chargeback History',
        first_order_high_value: 'First Order High Value',
        ip_proxy_vpn:           'IP Proxy / VPN',
        risky_country_combo:    'Risky Country Combo',
        mismatched_names:       'Mismatched Names',
        no_phone_number:        'No Phone Number',
        payment_method_risk:    'Payment Method Risk',
        multiple_addresses:     'Multiple Addresses',
        order_total_anomaly:    'Order Total Anomaly',
        odd_hours:              'Odd Hours',
        guest_checkout:         'Guest Checkout',
        new_account:            'New Account',
    };

    const tiers = [1, 2, 3];

    return (
        <div>

            {/* Header */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '16px' }}>
                <div>
                    <h2 style={{ margin: 0, fontSize: '16px', fontWeight: '600', color: '#1e293b' }}>
                        Signal Weight Personalization
                    </h2>
                    <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#64748b' }}>
                        {weights.calibration} · {weights.false_positives} overrides recorded
                    </p>
                </div>
                <button onClick={onReset} disabled={resetting} style={{
                    padding: '8px 14px', borderRadius: '8px',
                    fontSize: '12px', fontWeight: '500',
                    cursor: resetting ? 'not-allowed' : 'pointer',
                    border: '1px solid #e2e8f0',
                    background: '#fff', color: '#64748b',
                    opacity: resetting ? 0.6 : 1,
                }}>
                    {resetting ? '⏳ Resetting...' : '↺ Reset to Defaults'}
                </button>
            </div>
            {/* Personalization status */}
            <div style={{
                padding: '12px 16px', marginBottom: '20px',
                background: weights.personalized ? '#eff6ff' : '#f8fafc',
                border: `1px solid ${weights.personalized ? '#bfdbfe' : '#e2e8f0'}`,
                borderRadius: '10px', fontSize: '13px',
                color: weights.personalized ? '#1d4ed8' : '#64748b',
            }}>
                {weights.personalized
                    ? `✅ Your signal weights have been personalized based on ${weights.false_positives} false positive override${weights.false_positives !== 1 ? 's' : ''}. The system is learning your store's patterns.`
                    : '⚖️ Using global default weights. Signal weights will personalize automatically as you approve or override flagged orders.'
                }
            </div>

            {/* Weights by tier */}
            {tiers.map( tier => {
                const tierWeights = weights.weights.filter( w => w.tier === tier );
                const color = tierColors[tier];

                return (
                    <div key={tier} style={{ marginBottom: '20px' }}>
                        <div style={{
                            fontSize: '11px', fontWeight: '600',
                            color, textTransform: 'uppercase',
                            letterSpacing: '.06em', marginBottom: '8px',
                            display: 'flex', alignItems: 'center', gap: '8px'
                        }}>
                            <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: color }} />
                            {tierLabels[tier]}
                        </div>

                        <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', overflow: 'hidden' }}>
                            {tierWeights.map( ( w, i ) => (
                                <div key={w.signal} style={{
                                    display: 'grid',
                                    gridTemplateColumns: '200px 1fr 80px 100px 120px',
                                    padding: '10px 16px',
                                    borderBottom: i < tierWeights.length - 1 ? '1px solid #f1f5f9' : 'none',
                                    alignItems: 'center',
                                    gap: '12px',
                                }}>
                                    {/* Signal name */}
                                    <div style={{ fontSize: '13px', color: '#1e293b', fontWeight: '500' }}>
                                        {signalLabels[w.signal] || w.signal}
                                    </div>

                                    {/* Weight bar */}
                                    <div style={{ position: 'relative' }}>
                                        {/* Default weight marker */}
                                        <div style={{
                                            position: 'absolute',
                                            left: `${w.default_weight * 100 / 0.30 * 100}%`,
                                            top: '-4px', bottom: '-4px',
                                            width: '1px',
                                            background: '#cbd5e1',
                                        }} />
                                        {/* Current weight bar */}
                                        <div style={{ height: '8px', background: '#f1f5f9', borderRadius: '4px' }}>
                                            <div style={{
                                                height: '8px', borderRadius: '4px',
                                                width: `${w.current_weight / 0.30 * 100}%`,
                                                background: w.reduced ? '#f59e0b' : color,
                                                transition: 'width 0.3s',
                                            }} />
                                        </div>
                                    </div>

                                    {/* Current weight value */}
                                    <div style={{
                                        fontFamily: 'monospace', fontSize: '12px',
                                        color: w.reduced ? '#f59e0b' : '#475569',
                                        fontWeight: '600', textAlign: 'right'
                                    }}>
                                        {w.current_weight.toFixed(3)}
                                    </div>

                                    {/* Reduction badge */}
                                    <div style={{ textAlign: 'center' }}>
                                        {w.reduced ? (
                                            <span style={{
                                                padding: '2px 8px', borderRadius: '20px',
                                                fontSize: '11px', fontWeight: '600',
                                                background: '#fffbeb', color: '#d97706',
                                                border: '1px solid #fde68a',
                                            }}>
                                                -{w.reduction}% reduced
                                            </span>
                                        ) : (
                                            <span style={{
                                                padding: '2px 8px', borderRadius: '20px',
                                                fontSize: '11px', color: '#94a3b8',
                                                background: '#f8fafc', border: '1px solid #e2e8f0',
                                            }}>
                                                default
                                            </span>
                                        )}
                                    </div>

                                    {/* Override count */}
                                    <div style={{ fontSize: '12px', color: '#94a3b8', textAlign: 'right' }}>
                                        {w.times_overridden > 0
                                            ? `${w.times_overridden} override${w.times_overridden !== 1 ? 's' : ''}`
                                            : '—'
                                        }
                                    </div>

                                </div>
                            ))}
                        </div>
                    </div>
                );
            })}

            {/* Explanation */}
            <div style={{
                padding: '12px 16px', marginTop: '8px',
                background: '#f8fafc', border: '1px solid #e2e8f0',
                borderRadius: '10px', fontSize: '12px', color: '#64748b',
                lineHeight: '1.6'
            }}>
                <strong style={{ color: '#475569' }}>How personalization works:</strong>
                {' '}When you mark a blocked order as "Not Fraud", the signals that triggered that block
                are slightly reduced (-0.02 per override, minimum 0.01).
                After 10+ overrides, your weights are calibrated to your store's specific patterns.
                <strong style={{ color: '#f59e0b' }}> Yellow bars</strong> show signals that have been reduced.
            </div>

        </div>
    );
};

// ── Main Dashboard ─────────────────────────────
const FraudDashboard = () => {

    const [stats, setStats]     = useState( null );
    const [orders, setOrders]   = useState( [] );
    const [loading, setLoading] = useState( true );
    const [filter, setFilter]   = useState( 'ALL' );
    const [selected, setSelected] = useState( null );
    const [activeTab, setActiveTab] = useState('orders'); // 'orders' or 'weights'
    const [weights, setWeights]     = useState( null );
    const [resetting, setResetting] = useState( false );

    useEffect( () => { 
        fetchData(); 
        fetchWeights();
    }, [] );

    const fetchData = async () => {
        setLoading( true );
        try {
            const [s, o] = await Promise.all([
                api.get( 'stats' ),
                api.get( 'orders' )
            ]);
            setStats( s );
            setOrders( o );
        } catch (e) {
            console.error( 'Failed to fetch', e );
        }
        setLoading( false );
    };

    const fetchWeights = async () => {
        try {
            const w = await api.get('weights');
            setWeights(w);
        } catch(e) {
            console.error('Failed to fetch weights', e);
        }
    };

    const filteredOrders = orders.filter( o => {
        if ( filter === 'ALL' )      return true;
        if ( filter === 'BLOCK' )    return o.decision === 'BLOCK' || o.decision === 'BLOCKED';
        if ( filter === 'REVIEW' )   return o.decision === 'REVIEW';
        if ( filter === 'ALLOW' )    return o.decision === 'ALLOW' || o.decision === 'APPROVED';
        if ( filter === 'PENDING' )  return o.review_status === 'PENDING';
        return true;
    });

    const selectedOrder = orders.find( o => o.id === selected );

    return (
        <div style={{ padding: '20px', maxWidth: '1200px', fontFamily: 'DM Sans, sans-serif' }}>

            {/* Header */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '24px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <div style={{
                        width: '40px', height: '40px',
                        background: 'linear-gradient(135deg,#f97316,#ef4444)',
                        borderRadius: '10px', display: 'flex',
                        alignItems: 'center', justifyContent: 'center', fontSize: '20px'
                    }}>🛡️</div>
                    <div>
                        <h1 style={{ margin: 0, fontSize: '22px', fontWeight: '600', color: '#1e293b' }}>
                            AI Fraud Detector
                        </h1>
                        <p style={{ margin: '2px 0 0', fontSize: '13px', color: '#64748b' }}>
                            WooCommerce Protection Dashboard
                        </p>
                    </div>
                </div>
                <div style={{
                    padding: '6px 14px', background: '#eff6ff',
                    border: '1px solid #bfdbfe', borderRadius: '20px',
                    fontSize: '12px', color: '#3b82f6'
                }}>
                    ● Live Protection Active
                </div>
            </div>

            {stats && (
                <div style={{
                    display: 'flex', gap: '8px', marginBottom: '16px', fontSize: '11px'
                }}>
                    <span style={{
                        padding: '4px 10px', borderRadius: '12px',
                        background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0'
                    }}>
                        ✅ Local Scoring: Active
                    </span>
                    <span style={{
                        padding: '4px 10px', borderRadius: '12px',
                        background: stats.tier1_active ? '#eff6ff' : '#f8fafc',
                        color: stats.tier1_active ? '#1d4ed8' : '#94a3b8',
                        border: `1px solid ${stats.tier1_active ? '#bfdbfe' : '#e2e8f0'}`
                    }}>
                        {stats.tier1_active ? '✅' : '○'} Vector Memory: {stats.tier1_active ? 'Active' : 'Off'}
                    </span>
                    <span style={{
                        padding: '4px 10px', borderRadius: '12px',
                        background: stats.tier2_active ? '#eff6ff' : '#f8fafc',
                        color: stats.tier2_active ? '#1d4ed8' : '#94a3b8',
                        border: `1px solid ${stats.tier2_active ? '#bfdbfe' : '#e2e8f0'}`
                    }}>
                        {stats.tier2_active ? '✅' : '○'} AI Reasoning: {stats.tier2_active ? 'Active' : 'Off'}
                    </span>
                </div>
            )}

            {/* Tab Navigation */}
            <div style={{ display: 'flex', gap: '8px', marginBottom: '24px', borderBottom: '1px solid #e2e8f0', paddingBottom: '0' }}>
                {[
                    { id: 'orders',  label: '🛡️ Orders' },
                    { id: 'weights', label: '⚖️ Signal Weights' },
                ].map( tab => (
                    <button key={tab.id} onClick={() => setActiveTab(tab.id)} style={{
                        padding: '8px 16px',
                        borderRadius: '8px 8px 0 0',
                        fontSize: '13px', fontWeight: '500',
                        cursor: 'pointer',
                        border: 'none',
                        borderBottom: activeTab === tab.id ? '2px solid #3b82f6' : '2px solid transparent',
                        background: activeTab === tab.id ? '#eff6ff' : 'transparent',
                        color: activeTab === tab.id ? '#3b82f6' : '#64748b',
                    }}>
                        {tab.label}
                    </button>
                ))}
            </div>

            {loading ? (
                <div style={{ textAlign: 'center', padding: '60px' }}><Spinner /></div>
            ) : (
                <>
                    {/* Stats */}
                    <div style={{ display: 'flex', gap: '12px', marginBottom: '12px' }}>
                        <StatCard value={stats?.blocked ?? 0}         label="Blocked Orders"     color="#ef4444" />
                        <StatCard value={stats?.review ?? 0}          label="Need Review"        color="#f59e0b" />
                        <StatCard value={stats?.allowed ?? 0}         label="Approved"           color="#10b981" />
                        <StatCard value={Number(stats?.saved ?? 0).toFixed(0)} label="Fraud Prevented" color="#6366f1" prefix="$" />
                    </div>

                    {/* Feedback Stats */}
                    <div style={{ display: 'flex', gap: '12px', marginBottom: '24px' }}>
                        <StatCard value={stats?.confirmed_fraud ?? 0}  label="Confirmed Fraud"   color="#dc2626" />
                        <StatCard value={stats?.false_positives ?? 0}  label="False Positives"   color="#0ea5e9" />
                    </div>

                    {/* Filter Tabs */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' }}>
                        <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b', textTransform: 'uppercase', letterSpacing: '.06em' }}>
                            Flagged Orders ({filteredOrders.length})
                        </span>
                        <div style={{ display: 'flex', gap: '6px' }}>
                            {['ALL', 'BLOCK', 'REVIEW', 'PENDING', 'ALLOW'].map( f => (
                                <button key={f} onClick={() => setFilter(f)} style={{
                                    padding: '5px 12px', borderRadius: '6px',
                                    fontSize: '11px', fontWeight: '500',
                                    cursor: 'pointer', border: 'none',
                                    background: filter === f ? '#1e3a5f' : '#f1f5f9',
                                    color: filter === f ? '#60a5fa' : '#64748b',
                                }}>
                                    {f}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Orders Table */}
                    {activeTab === 'orders' && (
                        <>
                            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '12px', overflow: 'hidden' }}>
                                <div style={{
                                    display: 'grid',
                                    gridTemplateColumns: '60px 1fr 130px 100px 90px 90px 160px',
                                    padding: '10px 16px', background: '#f8fafc',
                                    borderBottom: '1px solid #e2e8f0',
                                    fontSize: '11px', fontWeight: '600',
                                    color: '#64748b', textTransform: 'uppercase', letterSpacing: '.05em'
                                }}>
                                    <div>Order</div>
                                    <div>Customer</div>
                                    <div>AI Score</div>
                                    <div>Decision</div>
                                    <div>Countries</div>
                                    <div>Amount</div>
                                    <div>Feedback</div>
                                </div>

                                {filteredOrders.length === 0 ? (
                                    <div style={{ padding: '40px', textAlign: 'center', color: '#94a3b8', fontSize: '14px' }}>
                                        ✅ No flagged orders — your store is safe!
                                    </div>
                                ) : (
                                    filteredOrders.map( order => (
                                        <div key={order.id}>
                                            <div
                                                onClick={() => setSelected( selected === order.id ? null : order.id )}
                                                style={{
                                                    display: 'grid',
                                                    gridTemplateColumns: '60px 1fr 130px 100px 90px 90px 160px',
                                                    padding: '12px 16px',
                                                    borderBottom: '1px solid #f1f5f9',
                                                    alignItems: 'center',
                                                    cursor: 'pointer',
                                                    background: selected === order.id ? '#f8fafc' : 'transparent',
                                                }}
                                                onMouseEnter={e => e.currentTarget.style.background = '#f8fafc'}
                                                onMouseLeave={e => e.currentTarget.style.background = selected === order.id ? '#f8fafc' : 'transparent'}
                                            >
                                                <div style={{ fontFamily: 'monospace', fontSize: '13px', color: '#3b82f6', fontWeight: '600' }}>
                                                    #{order.id}
                                                </div>
                                                <div>
                                                    <div style={{ fontSize: '13px', color: '#1e293b', fontWeight: '500' }}>
                                                        {order.customer_name}
                                                    </div>
                                                    <div style={{ fontSize: '11px', color: '#94a3b8', marginTop: '2px' }}>
                                                        {order.customer_email}
                                                    </div>
                                                </div>
                                                <ScoreBar score={order.risk_score} />
                                                <Badge decision={order.decision} />
                                                <div style={{ fontFamily: 'monospace', fontSize: '12px', color: '#94a3b8' }}>
                                                    {order.billing_country}→{order.shipping_country}
                                                </div>
                                                <div style={{ fontFamily: 'monospace', fontSize: '13px', fontWeight: '600', color: '#1e293b' }}>
                                                    ${order.total}
                                                </div>
                                                <div onClick={e => e.stopPropagation()}>
                                                    <FeedbackButtons
                                                        order={order}
                                                        onAction={fetchData}
                                                    />
                                                </div>
                                            </div>

                                            {/* Expanded Detail Row */}
                                            {selected === order.id && (
                                                <div style={{
                                                    padding: '16px',
                                                    background: '#f8fafc',
                                                    borderBottom: '1px solid #e2e8f0',
                                                }}>
                                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>

                                                        {/* AI Reason */}
                                                        <div>
                                                            <div style={{ fontSize: '11px', fontWeight: '600', color: '#64748b', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: '8px' }}>
                                                                AI Analysis
                                                            </div>
                                                            <div style={{ fontSize: '13px', color: '#475569', lineHeight: '1.6', padding: '12px', background: '#fff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                                                                {order.reason || 'No AI reason available'}
                                                            </div>
                                                        </div>

                                                        {/* Review Status */}
                                                        <div>
                                                            <div style={{ fontSize: '11px', fontWeight: '600', color: '#64748b', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: '8px' }}>
                                                                Review Status
                                                            </div>
                                                            <div style={{ padding: '12px', background: '#fff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                                                                <ReviewBadge status={order.review_status || 'PENDING'} />
                                                                <p style={{ margin: '8px 0 0', fontSize: '12px', color: '#64748b' }}>
                                                                    {order.review_status === 'PENDING' && 'Awaiting your review. Confirm as fraud or mark as legitimate.'}
                                                                    {order.review_status === 'CONFIRMED_FRAUD' && 'You confirmed this as fraud. Pattern saved to Pinecone.'}
                                                                    {order.review_status === 'FALSE_POSITIVE' && 'You marked this as legitimate. Signal weights adjusted.'}
                                                                </p>
                                                            </div>
                                                        </div>

                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ))
                                )}
                            </div>
                        </>
                    )}
                    {/* Weights Tab */}
                    {activeTab === 'weights' && weights && (
                        <WeightsPanel
                            weights={weights}
                            onReset={async () => {
                                if ( ! confirm('Reset all signal weights to defaults? This will lose your personalization.') ) return;
                                setResetting(true);
                                await api.post('weights/reset');
                                await fetchWeights();
                                setResetting(false);
                            }}
                            resetting={resetting}
                        />
                    )}
                </>
            )}
        </div>
    );
};

// ── Mount ──────────────────────────────────────
const container = document.getElementById( 'aifd-dashboard-root' );
if ( container ) {
    render( <FraudDashboard />, container );
}